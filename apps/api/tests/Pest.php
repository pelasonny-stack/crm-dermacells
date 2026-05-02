<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Configuration
|--------------------------------------------------------------------------
|
| This file bootstraps Pest for the CRM Dermacells test suite.
|
| - All Feature and Unit tests run inside Tests\TestCase so that Laravel's
|   application container, service providers, and database are available.
| - Feature tests additionally use RefreshDatabase so each test starts from
|   a clean slate without leaking data to the next test.
| - Custom expectations are registered here to keep test bodies concise.
|
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');

// DatabaseTransactions (NOT RefreshDatabase) — the test DB is migrated
// externally by `php artisan migrate:fresh --database=pgsql_test_migration`
// before the suite runs. RefreshDatabase would attempt to run migrations
// on the default connection (pgsql_test = app_role) which lacks DDL,
// and would also clear seeded reference data.
uses(\Illuminate\Foundation\Testing\DatabaseTransactions::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert that a value is a valid UUID v4 string.
 *
 * Example: expect($user->id)->toBeUuid();
 */
expect()->extend('toBeUuid', function (): \Pest\Expectation {
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    expect($this->value)
        ->toBeString()
        ->toMatch($pattern, "Expected [{$this->value}] to be a valid UUID v4.");

    return $this;
});

/**
 * Assert that a response carries an RFC 7807 problem+json body
 * with the given business error code.
 *
 * Example: expect($response)->toHaveProblemCode('IDLE_TIMEOUT');
 */
expect()->extend('toHaveProblemCode', function (string $code): \Pest\Expectation {
    $body = is_string($this->value->getContent())
        ? json_decode($this->value->getContent(), true)
        : [];

    expect($body)
        ->toHaveKey('code')
        ->and($body['code'])->toBe($code, "Expected problem code [{$code}] but got [{$body['code']}].");

    return $this;
});

/**
 * Assert that a value is a valid HMAC-SHA256 hex string (64 chars).
 *
 * Example: expect($row->row_hash)->toBeHmacSha256();
 */
expect()->extend('toBeHmacSha256', function (): \Pest\Expectation {
    expect($this->value)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[0-9a-f]{64}$/', "Expected a 64-character lowercase hex string.");

    return $this;
});

/**
 * Assert that a Sanctum token response is well-formed.
 *
 * Example: expect($response)->toHaveSanctumToken();
 */
expect()->extend('toHaveSanctumToken', function (): \Pest\Expectation {
    $body = is_string($this->value->getContent())
        ? json_decode($this->value->getContent(), true)
        : [];

    expect($body)->toHaveKey('token');
    expect($body['token'])->toBeString()->not->toBeEmpty();

    return $this;
});
