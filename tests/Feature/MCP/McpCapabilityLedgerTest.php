<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These tests pin the ledger's invariants so drift is caught immediately,
 * since the ledger is the single source of truth for MCP capability status.
 */
function ledgerPath(): string
{
    return realpath(__DIR__.'/../../..').'/resources/mcp/capability-ledger.json';
}

function ledgerEntries(): array
{
    $path = ledgerPath();

    expect(file_exists($path))->toBeTrue("capability ledger file is missing at {$path}");
    expect(is_readable($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE, 'ledger is not valid JSON: '.json_last_error_msg());
    expect($decoded)->toBeArray();
    expect(array_is_list($decoded))->toBeTrue('ledger root must be a list of entries');

    return $decoded;
}

function entryKey(array $entry): string
{
    return $entry['method'].' '.$entry['path'];
}

function indexByKey(array $entries): array
{
    $indexed = [];
    foreach ($entries as $entry) {
        $indexed[entryKey($entry)] = $entry;
    }

    return $indexed;
}

function filterBy(array $entries, string $field, mixed $value): array
{
    return array_values(array_filter($entries, fn (array $e) => $e[$field] === $value));
}

function pluckKeys(array $entries): array
{
    return array_values(array_map('entryKey', $entries));
}

test('ledger exists and decodes to a list of entries', function () {
    $entries = ledgerEntries();

    expect($entries)->not->toBeEmpty();
});

test('ledger contains exactly 146 operation entries', function () {
    $entries = ledgerEntries();

    expect($entries)->toHaveCount(146);
});

test('ledger marks exactly 17 deprecated project-scoped provider/storage/source-control duplicates as excluded with a reason', function () {
    $entries = ledgerEntries();

    $excluded = filterBy($entries, 'status', 'excluded');
    expect($excluded)->toHaveCount(17);

    foreach ($excluded as $entry) {
        expect($entry)->toHaveKey('reason');
        expect($entry['reason'])->toBeString();
        expect($entry['reason'])->not->toBeEmpty();
        expect(strtolower($entry['reason']))->toContain('deprecated');
        expect(strtolower($entry['reason']))->toContain('duplicate');
    }
});

test('ledger keeps exactly 129 canonical (non-excluded) operations', function () {
    $entries = ledgerEntries();

    $canonical = array_values(array_filter($entries, fn (array $e) => $e['status'] !== 'excluded'));
    expect($canonical)->toHaveCount(129);
    expect(count($canonical))->toBe(146 - 17);
});

test('ledger implements exactly the four native capabilities', function () {
    $entries = ledgerEntries();
    $byKey = indexByKey($entries);

    $implemented = pluckKeys(filterBy($entries, 'status', 'implemented'));
    sort($implemented);

    $expectedNativeCapabilities = [
        'GET /api/projects',
        'GET /api/projects/{project}/servers',
        'GET /api/projects/{project}/servers/{server}',
        'POST /api/projects/{project}/servers/{server}/reboot',
    ];

    expect($implemented)->toHaveCount(4);
    expect($implemented)->toEqualCanonicalizing($expectedNativeCapabilities);

    $reboot = $byKey['POST /api/projects/{project}/servers/{server}/reboot'] ?? null;
    expect($reboot)->not->toBeNull();
    expect($reboot['status'])->toBe('implemented');
    expect($reboot['risk'])->toBe('destructive');
});

test('ledger classifies exactly 30 operations as destructive', function () {
    $entries = ledgerEntries();
    $byKey = indexByKey($entries);

    $destructive = filterBy($entries, 'risk', 'destructive');
    expect($destructive)->toHaveCount(30);

    $deletes = filterBy($entries, 'method', 'DELETE');
    foreach ($deletes as $delete) {
        expect($delete['risk'])->toBe('destructive', entryKey($delete).' must be destructive');
    }

    $namedExtras = [
        'POST /api/projects/{project}/servers/{server}/reboot',
        'POST /api/projects/{project}/servers/{server}/upgrade',
        'POST /api/projects/{project}/servers/{server}/sites/{site}/retry',
        'POST /api/projects/{project}/servers/{server}/sites/{site}/disable-ssl',
        'POST /api/projects/{project}/servers/{server}/services/{service}/stop',
        'POST /api/projects/{project}/servers/{server}/services/{service}/disable',
        'POST /api/projects/{project}/domains/{domain}/records/sync',
    ];
    foreach ($namedExtras as $key) {
        expect($byKey[$key] ?? null)->not->toBeNull("destructive extra missing from ledger: {$key}");
        expect($byKey[$key]['risk'])->toBe('destructive', "{$key} must be destructive");
    }
});

test('every entry has a unique operationId and a valid shape', function () {
    $entries = ledgerEntries();

    $requiredKeys = ['operationId', 'method', 'path', 'domain', 'risk', 'status'];
    $validRisks = ['read', 'write', 'destructive'];
    $validStatuses = ['implemented', 'planned', 'excluded'];
    $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

    $seenIds = [];
    foreach ($entries as $entry) {
        foreach ($requiredKeys as $key) {
            expect($entry)->toHaveKey($key);
        }

        expect($entry['operationId'])->toBeString();
        expect($entry['operationId'])->not->toBeEmpty();
        expect($entry['path'])->toStartWith('/api');
        expect($entry['domain'])->toBeString();
        expect($entry['domain'])->not->toBeEmpty();
        expect($entry['method'])->toBeIn($validMethods);
        expect($entry['risk'])->toBeIn($validRisks);
        expect($entry['status'])->toBeIn($validStatuses);

        expect($seenIds)->not->toContain($entry['operationId']);
        $seenIds[] = $entry['operationId'];
    }

    expect($seenIds)->toHaveCount(146);
});
