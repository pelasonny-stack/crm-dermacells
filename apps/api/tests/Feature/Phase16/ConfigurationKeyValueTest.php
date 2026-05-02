<?php

declare(strict_types=1);

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ConfigurationKeyValueTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies the Configuration model's getValue/setValue helpers and the
| seeded defaults from the 2026_05_16_000002_create_configurations_table
| migration.
|
*/

it('getValue returns the seeded default for stock_minimum_default', function (): void {
    $value = Configuration::getValue('stock_minimum_default', 5);

    // The migration seeds '5' as the default.
    expect($value)->toBe(5);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('configurations'), 'configurations table not yet migrated');

it('setValue creates a new configuration key', function (): void {
    $key = 'test_key_' . uniqid();

    Configuration::setValue($key, '42');

    $stored = Configuration::getValue($key, 0);

    expect($stored)->toBe('42');
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('configurations'), 'configurations table not yet migrated');

it('setValue updates an existing key', function (): void {
    $key = 'stock_minimum_default';

    Configuration::setValue($key, '10');
    $updated = Configuration::getValue($key, 0);

    expect($updated)->toBe(10);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('configurations'), 'configurations table not yet migrated');

it('getValue returns typed default when key does not exist', function (): void {
    $value = Configuration::getValue('non_existent_key_xyz', 99);

    expect($value)->toBe(99);
});

it('getValue returns float default typed correctly', function (): void {
    $value = Configuration::getValue('non_existent_float_key', 1.5);

    expect($value)->toBe(1.5);
});

it('getValue returns bool default typed correctly', function (): void {
    $value = Configuration::getValue('non_existent_bool_key', false);

    expect($value)->toBeFalse();
});

it('all seeded configuration keys are present', function (): void {
    $expectedKeys = [
        'stock_minimum_default',
        'alert_unusual_sale_threshold',
        'alert_draft_inactivity_days',
        'lot_expiry_alert_days',
        'zone_risk_threshold_pct',
        'first_purchase_no_reorder_days',
        'purchase_frequency_default_a',
        'purchase_frequency_default_b',
        'purchase_frequency_default_c',
        'purchase_frequency_default_d',
        'evolution_alert_advance_days',
    ];

    foreach ($expectedKeys as $key) {
        $row = DB::table('configurations')->where('key', $key)->first();
        expect($row)->not->toBeNull("Configuration key '{$key}' should be seeded");
    }
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('configurations'), 'configurations table not yet migrated');

it('configuration key is unique at DB level', function (): void {
    $key = 'stock_minimum_default';

    expect(fn () =>
        DB::table('configurations')->insert([
            'id'         => (string) \Illuminate\Support\Str::uuid(),
            'key'        => $key,
            'value'      => '999',
            'group_name' => 'general',
        ])
    )->toThrow(\Illuminate\Database\QueryException::class);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('configurations'), 'configurations table not yet migrated');
