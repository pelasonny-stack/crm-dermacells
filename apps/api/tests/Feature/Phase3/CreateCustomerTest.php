<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 3 — Create Customer Tests
|--------------------------------------------------------------------------
|
| Covers §3.1, §3.9: CUIT format validation, uniqueness, and happy-path
| customer creation.
|
| All tests run inside DatabaseTransactions (configured in Pest.php) so
| inserts are rolled back automatically. setUp() in TestCase sets the RLS
| GUCs to director scope so factory inserts pass WITH CHECK predicates.
*/

function makeCustomerPayload(array $overrides = []): array
{
    $seller  = User::factory()->seller()->create();
    $zone    = Zone::factory()->create(['distributor_id' => User::factory()->distributor()->create()->id]);
    $cat     = CustomerCategory::factory()->create();
    $terms   = PaymentTerm::factory()->create();

    return array_merge([
        'first_name'               => 'Ana',
        'last_name'                => 'García',
        'cuit'                     => '20123456789',
        'phone'                    => '+5491112345678',
        'email'                    => 'ana.garcia@example.com',
        'address'                  => 'Av. Corrientes 1234, CABA',
        'category_id'              => $cat->id,
        'zone_id'                  => $zone->id,
        'assigned_seller_id'       => $seller->id,
        'default_payment_terms_id' => $terms->id,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// 1. Happy path
// ---------------------------------------------------------------------------

it('creates a customer with valid data and returns 201', function (): void {
    $director = User::factory()->director()->create();

    $payload = makeCustomerPayload();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.cuit', '20123456789')
        ->assertJsonPath('data.first_name', 'Ana')
        ->assertJsonPath('data.is_active', true);

    expect(Customer::where('cuit', '20123456789')->exists())->toBeTrue();
});

it('returns a meta.permissions block in the creation response', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload());

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['meta' => ['permissions']]]);
});

it('seller can create a customer assigned to themselves', function (): void {
    $seller = User::factory()->seller()->create();

    $zone  = Zone::factory()->create(['distributor_id' => User::factory()->distributor()->create()->id]);
    $cat   = CustomerCategory::factory()->create();
    $terms = PaymentTerm::factory()->create();

    $payload = [
        'first_name'               => 'Carlos',
        'last_name'                => 'López',
        'cuit'                     => '23456789012',
        'phone'                    => '+5491187654321',
        'email'                    => 'carlos@example.com',
        'address'                  => 'Callao 500, CABA',
        'category_id'              => $cat->id,
        'zone_id'                  => $zone->id,
        'assigned_seller_id'       => $seller->id,
        'default_payment_terms_id' => $terms->id,
    ];

    // Seller creates in director GUC scope (test default), so the RLS
    // WITH CHECK on assigned_seller_id would fire on real DB.
    // We set seller scope to test the API layer allows the request.
    DB::statement(sprintf("SET LOCAL app.user_id = '%s'", $seller->id));
    DB::statement("SET LOCAL app.user_role = 'seller'");

    $response = $this->actingAs($seller, 'sanctum')
        ->postJson('/api/v1/customers', $payload);

    $response->assertCreated();
});

// ---------------------------------------------------------------------------
// 2. CUIT format validation
// ---------------------------------------------------------------------------

it('rejects CUIT with invalid checksum and returns 422', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload(['cuit' => '20123456780']));  // wrong checksum digit

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cuit']);
});

it('rejects CUIT that is not 11 digits and returns 422', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload(['cuit' => '1234567']));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cuit']);
});

it('rejects CUIT with letters and returns 422', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload(['cuit' => '2012345678X']));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cuit']);
});

it('accepts CUIT formatted with hyphens and strips them', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload(['cuit' => '20-12345678-9']));

    // Hyphenated CUIT '20-12345678-9' is the same as '20123456789' — valid checksum
    $response->assertCreated();
    $data = $response->json('data');
    expect($data['cuit'])->toBe('20123456789');
});

// ---------------------------------------------------------------------------
// 3. Duplicate CUIT (§3.9)
// ---------------------------------------------------------------------------

it('rejects duplicate CUIT and returns 422 with seller name in message', function (): void {
    $director = User::factory()->director()->create();
    $seller   = User::factory()->seller()->create(['full_name' => 'Martín Ríos']);

    // Create the first customer with this CUIT
    Customer::factory()->withCuit('20123456789')->assignedTo($seller)->create();

    // Attempt to create a second customer with the same CUIT
    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', makeCustomerPayload(['cuit' => '20123456789']));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cuit']);

    // The validation message must name the existing seller (§3.9)
    $errors  = $response->json('errors.cuit');
    $message = is_array($errors) ? implode(' ', $errors) : (string) $errors;
    expect($message)->toContain('Martín Ríos');
    expect($message)->toContain('Director');
});

// ---------------------------------------------------------------------------
// 4. Required field validation
// ---------------------------------------------------------------------------

it('rejects missing required fields with 422', function (): void {
    $director = User::factory()->director()->create();

    $response = $this->actingAs($director, 'sanctum')
        ->postJson('/api/v1/customers', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'first_name', 'last_name', 'cuit', 'phone',
            'email', 'address', 'category_id', 'zone_id',
            'assigned_seller_id', 'default_payment_terms_id',
        ]);
});

// ---------------------------------------------------------------------------
// 5. Unauthenticated request
// ---------------------------------------------------------------------------

it('returns 401 for unauthenticated customer creation', function (): void {
    $this->postJson('/api/v1/customers', makeCustomerPayload())
        ->assertUnauthorized();
});
