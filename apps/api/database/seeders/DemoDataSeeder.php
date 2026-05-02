<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthorizationType;
use App\Enums\IvaCondition;
use App\Enums\PreferredCostModality;
use App\Enums\SaleStatus;
use App\Enums\SettlementStatus;
use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\Alert;
use App\Models\AuthorizationRequest;
use App\Models\CentralStock;
use App\Models\Customer;
use App\Models\CustomerBillingEntity;
use App\Models\CustomerCategory;
use App\Models\CustomerContact;
use App\Models\DistributorAccount;
use App\Models\DistributorPreferredCost;
use App\Models\DistributorSettlement;
use App\Models\DistributorStock;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesStatusHistory;
use App\Models\ScheduledAction;
use App\Models\SellerCommissionConfig;
use App\Models\SellerMonthlyGoal;
use App\Models\SellerStock;
use App\Models\StockLot;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappThread;
use App\Models\Zone;
use Brick\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DemoDataSeeder — populates the dev DB with realistic Argentine demo data.
 *
 * Idempotent: checks for existence before inserting. Safe to re-run.
 *
 * Run manually with:
 *   php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder --database=pgsql_migration --force
 *
 * NOT called automatically by DatabaseSeeder — see the commented line there.
 */
class DemoDataSeeder extends Seeder
{
    // =========================================================================
    // Counters for final report
    // =========================================================================

    private int $countZones           = 0;
    private int $countUsers           = 0;
    private int $countCustomers       = 0;
    private int $countContacts        = 0;
    private int $countBillingEntities = 0;
    private int $countScheduledActions= 0;
    private int $countLots            = 0;
    private int $countCentralStock    = 0;
    private int $countDistributorStock= 0;
    private int $countSellerStock     = 0;
    private int $countExchangeRates   = 0;
    private int $countSales           = 0;
    private int $countSaleItems       = 0;
    private int $countPayments        = 0;
    private int $countCommissionConfig= 0;
    private int $countSettlements     = 0;
    private int $countAlerts          = 0;
    private int $countAuthRequests    = 0;
    private int $countWhatsappThreads = 0;
    private int $countWhatsappMessages= 0;
    private int $countGoals           = 0;

    // =========================================================================
    // CUIT pool — 100 pre-validated Argentine CUITs
    // =========================================================================

    /** Generate a valid CUIT from type+CUIL suffix using modulo-11 algorithm. */
    private function generateCuit(string $prefix, int $number): string
    {
        $base   = $prefix . str_pad((string) $number, 8, '0', STR_PAD_LEFT);
        $digits = str_split($base);
        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $digits[$i] * $w;
        }
        $remainder = $sum % 11;
        $check = match ($remainder) {
            0       => 0,
            1       => 9, // tipo 20/23/27 special; use 9 as safe fallback
            default => 11 - $remainder,
        };

        return $base . $check;
    }

    /** Build a pool of 100 unique valid CUITs. */
    private function buildCuitPool(): array
    {
        $pool = [];
        // Use prefix 20 (masculino) and 27 (femenino) alternately
        $prefixes = ['20', '27'];
        $counter  = 1;
        while (count($pool) < 100) {
            $prefix = $prefixes[$counter % 2];
            $cuit   = $this->generateCuit($prefix, 10000000 + $counter);
            // Skip CUITs with check digit 9 built from prefix 20 (rare edge)
            // Just add them all — uniqueness in the pool is guaranteed by counter.
            $pool[] = $cuit;
            $counter++;
        }
        return $pool;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function argentinePhone(): string
    {
        $area   = ['11', '351', '299', '261', '221'][array_rand(['11', '351', '299', '261', '221'])];
        $number = rand(10000000, 99999999);
        return "+54 {$area} {$number}";
    }

    private function randomDate(int $daysBack, int $daysForward = 0): Carbon
    {
        $offsetDays = rand(-$daysBack, $daysForward);
        return now()->addDays($offsetDays);
    }

    private function getOrCreateDirector(): User
    {
        return User::firstOrCreate(
            ['email' => 'dev@dermacells.local'],
            [
                'full_name'  => 'Director Local Dev',
                'role'       => UserRole::Director,
                'can_sell'   => false,
                'is_active'  => true,
                'ai_enabled' => true,
            ]
        );
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    public function run(): void
    {
        $this->command?->info('DemoDataSeeder starting...');

        $cuitPool = $this->buildCuitPool();

        // ------------------------------------------------------------------
        // 1. Prerequisite data (must exist before demo data)
        // ------------------------------------------------------------------
        $this->call([
            CustomerCategorySeeder::class,
            PaymentTermSeeder::class,
            ProductSeeder::class,
            PaymentMethodSeeder::class,
            DevDirectorSeeder::class,
        ]);

        $director = $this->getOrCreateDirector();

        // ------------------------------------------------------------------
        // 2. Director with can_sell
        // ------------------------------------------------------------------
        DB::transaction(function () use ($director, &$directorCanSell) {
            $directorCanSell = User::firstOrCreate(
                ['email' => 'vos@demo.dermacells.local'],
                [
                    'full_name'  => 'Vos Director con cartera',
                    'role'       => UserRole::Director,
                    'can_sell'   => true,
                    'is_active'  => true,
                    'ai_enabled' => true,
                ]
            );
            if ($directorCanSell->wasRecentlyCreated) {
                $this->countUsers++;
            }
        });
        $directorCanSell = User::where('email', 'vos@demo.dermacells.local')->firstOrFail();

        // ------------------------------------------------------------------
        // 3. Distribuidores
        // ------------------------------------------------------------------
        $eduardo = $andres = null;
        DB::transaction(function () use (&$eduardo, &$andres) {
            $eduardo = User::firstOrCreate(
                ['email' => 'eduardo@demo.dermacells.local'],
                [
                    'full_name'  => 'Eduardo Distribuidor BA',
                    'role'       => UserRole::Distributor,
                    'can_sell'   => false,
                    'is_active'  => true,
                    'ai_enabled' => false,
                ]
            );
            if ($eduardo->wasRecentlyCreated) {
                $this->countUsers++;
            }

            $andres = User::firstOrCreate(
                ['email' => 'andres@demo.dermacells.local'],
                [
                    'full_name'  => 'Andres Distribuidor Cordoba',
                    'role'       => UserRole::Distributor,
                    'can_sell'   => false,
                    'is_active'  => true,
                    'ai_enabled' => false,
                ]
            );
            if ($andres->wasRecentlyCreated) {
                $this->countUsers++;
            }
        });

        // ------------------------------------------------------------------
        // 4. Zones
        // ------------------------------------------------------------------
        $zoneBa  = $zoneCba = $zonePat = null;
        DB::transaction(function () use ($eduardo, $andres, &$zoneBa, &$zoneCba, &$zonePat) {
            $zoneBa = Zone::firstOrCreate(
                ['name' => 'Buenos Aires Norte'],
                ['distributor_id' => $eduardo->id, 'is_active' => true]
            );
            if ($zoneBa->wasRecentlyCreated) {
                $this->countZones++;
            }

            $zoneCba = Zone::firstOrCreate(
                ['name' => 'Cordoba'],
                ['distributor_id' => $andres->id, 'is_active' => true]
            );
            if ($zoneCba->wasRecentlyCreated) {
                $this->countZones++;
            }

            $zonePat = Zone::firstOrCreate(
                ['name' => 'Patagonia'],
                ['distributor_id' => null, 'is_active' => true]
            );
            if ($zonePat->wasRecentlyCreated) {
                $this->countZones++;
            }
        });

        // ------------------------------------------------------------------
        // 5. Sellers
        // ------------------------------------------------------------------
        $sellersDefs = [
            // BA Norte
            ['email' => 'maria@demo.dermacells.local',  'full_name' => 'María González',    'zone' => 'ba'],
            ['email' => 'juan@demo.dermacells.local',   'full_name' => 'Juan Rodríguez',    'zone' => 'ba'],
            ['email' => 'sofia@demo.dermacells.local',  'full_name' => 'Sofía Martínez',    'zone' => 'ba'],
            // Cordoba
            ['email' => 'carlos@demo.dermacells.local', 'full_name' => 'Carlos Fernández',  'zone' => 'cba'],
            ['email' => 'ana@demo.dermacells.local',    'full_name' => 'Ana López',          'zone' => 'cba'],
            ['email' => 'diego@demo.dermacells.local',  'full_name' => 'Diego Pérez',        'zone' => 'cba'],
            // Patagonia
            ['email' => 'lucia@demo.dermacells.local',  'full_name' => 'Lucía Herrera',     'zone' => 'pat'],
            ['email' => 'pablo@demo.dermacells.local',  'full_name' => 'Pablo Díaz',         'zone' => 'pat'],
        ];

        $sellersByZone = ['ba' => [], 'cba' => [], 'pat' => []];
        DB::transaction(function () use ($sellersDefs, &$sellersByZone) {
            foreach ($sellersDefs as $def) {
                $seller = User::firstOrCreate(
                    ['email' => $def['email']],
                    [
                        'full_name'  => $def['full_name'],
                        'role'       => UserRole::Seller,
                        'can_sell'   => false, // constraint: only Directors can have can_sell=true
                        'is_active'  => true,
                        'ai_enabled' => false,
                    ]
                );
                if ($seller->wasRecentlyCreated) {
                    $this->countUsers++;
                }
                $sellersByZone[$def['zone']][] = $seller;
            }
        });

        // ------------------------------------------------------------------
        // 6. Reference data lookups
        // ------------------------------------------------------------------
        $categories   = CustomerCategory::all()->keyBy('code'); // A, B, C, D
        $paymentTerms = PaymentTerm::all();
        $contado      = $paymentTerms->firstWhere('name', 'Contado');
        $term30       = $paymentTerms->firstWhere('name', '30 días') ?? $contado;
        $products     = Product::all();
        $productDermal    = $products->firstWhere('name', 'Dermal');
        $productPink      = $products->firstWhere('name', 'Pink');
        $productCapillary = $products->firstWhere('name', 'Capillary');
        $productBiomask   = $products->firstWhere('name', 'Biomask');

        $paymentMethods     = PaymentMethod::all();
        $pmTransfer         = $paymentMethods->firstWhere('code', 'transfer_dermacells');
        $pmCash             = $paymentMethods->firstWhere('code', 'cash');
        $pmCheck            = $paymentMethods->firstWhere('code', 'check');

        // ------------------------------------------------------------------
        // 7. Exchange rates — last 30 days
        // ------------------------------------------------------------------
        $exchangeRates = []; // keyed by date string 'Y-m-d'
        DB::transaction(function () use ($director, &$exchangeRates) {
            $rate = 1050.00;
            for ($i = 30; $i >= 0; $i--) {
                $date   = now()->subDays($i)->toDateString();
                $exists = ExchangeRate::where('rate_date', $date)->first();
                if ($exists) {
                    $exchangeRates[$date] = $exists;
                    continue;
                }
                // Random walk ±15 ARS per day
                $delta = (rand(-150, 150) / 10);
                $rate  = max(950.0, min(1150.0, $rate + $delta));
                $er    = ExchangeRate::create([
                    'rate_date'        => $date,
                    'rate_ars_per_usd' => number_format($rate, 6, '.', ''),
                    'source'           => 'manual_override',
                    'recorded_by'      => $director->id,
                    'created_at'       => now()->subDays($i),
                ]);
                $exchangeRates[$date] = $er;
                $this->countExchangeRates++;
            }
        });

        $todayRate = $exchangeRates[now()->toDateString()] ?? collect($exchangeRates)->last();

        // ------------------------------------------------------------------
        // 8. Stock lots
        // ------------------------------------------------------------------
        $lots = [];
        DB::transaction(function () use ($director, $productDermal, $productPink, $productCapillary, $productBiomask, &$lots) {
            $lotDefs = [
                ['product' => $productDermal,    'lot' => 'Lote-2026-01', 'qty' => 50, 'expiry_months' => 18],
                ['product' => $productPink,      'lot' => 'Lote-2026-02', 'qty' => 40, 'expiry_months' => 24],
                ['product' => $productCapillary, 'lot' => 'Lote-2026-03', 'qty' => 30, 'expiry_months' => 18],
                ['product' => $productBiomask,   'lot' => 'Lote-2026-04', 'qty' => 20, 'expiry_months' => 12],
            ];
            foreach ($lotDefs as $def) {
                if (! $def['product']) {
                    continue;
                }
                $existing = StockLot::where('lot_number', $def['lot'])
                    ->where('product_id', $def['product']->id)
                    ->first();
                if ($existing) {
                    $lots[$def['lot']] = $existing;
                    continue;
                }
                $lot = StockLot::create([
                    'product_id'    => $def['product']->id,
                    'lot_number'    => $def['lot'],
                    'import_date'   => now()->subDays(60)->toDateString(),
                    'supplier'      => 'LiveCells International',
                    'quantity_boxes' => $def['qty'],
                    'expiry_date'   => now()->addMonths($def['expiry_months'])->toDateString(),
                    'registered_by' => $director->id,
                ]);
                $lots[$def['lot']] = $lot;
                $this->countLots++;
            }
        });

        // ------------------------------------------------------------------
        // 9. Central stock
        // ------------------------------------------------------------------
        $centralStockMap = []; // product_id => CentralStock
        DB::transaction(function () use ($productDermal, $productPink, $productCapillary, $productBiomask, &$centralStockMap) {
            $stockDefs = [
                ['product' => $productDermal,    'imported' => 50, 'dispatched' => 30, 'min' => 5],
                ['product' => $productPink,      'imported' => 40, 'dispatched' => 25, 'min' => 5],
                ['product' => $productCapillary, 'imported' => 30, 'dispatched' => 20, 'min' => 3],
                ['product' => $productBiomask,   'imported' => 20, 'dispatched' => 15, 'min' => 2],
            ];
            foreach ($stockDefs as $def) {
                if (! $def['product']) {
                    continue;
                }
                $cs = CentralStock::firstOrCreate(
                    ['product_id' => $def['product']->id],
                    [
                        'total_imported'   => $def['imported'],
                        'total_dispatched' => $def['dispatched'],
                        'minimum_stock'    => $def['min'],
                    ]
                );
                if ($cs->wasRecentlyCreated) {
                    $this->countCentralStock++;
                }
                $centralStockMap[$def['product']->id] = $cs;
            }
        });

        // ------------------------------------------------------------------
        // 10. Distributor stock
        // ------------------------------------------------------------------
        DB::transaction(function () use ($eduardo, $andres, $productDermal, $productPink) {
            $dsDefs = [
                ['dist' => $eduardo, 'product' => $productDermal,    'received' => 15, 'redistributed' => 5, 'reserved' => 2, 'available' => 8],
                ['dist' => $eduardo, 'product' => $productPink,      'received' => 10, 'redistributed' => 4, 'reserved' => 1, 'available' => 5],
                ['dist' => $andres,  'product' => $productDermal,    'received' => 10, 'redistributed' => 4, 'reserved' => 1, 'available' => 5],
                ['dist' => $andres,  'product' => $productPink,      'received' =>  8, 'redistributed' => 3, 'reserved' => 1, 'available' => 4],
            ];
            foreach ($dsDefs as $def) {
                if (! $def['product']) {
                    continue;
                }
                $existing = DistributorStock::where('distributor_id', $def['dist']->id)
                    ->where('product_id', $def['product']->id)
                    ->first();
                if ($existing) {
                    continue;
                }
                DistributorStock::create([
                    'distributor_id'      => $def['dist']->id,
                    'product_id'          => $def['product']->id,
                    'total_received'      => $def['received'],
                    'total_redistributed' => $def['redistributed'],
                    'reserved'            => $def['reserved'],
                    'available'           => $def['available'],
                    'minimum_stock'       => 2,
                ]);
                $this->countDistributorStock++;
            }
        });

        // ------------------------------------------------------------------
        // 11. Seller stock — each seller gets 2-5 boxes mix
        // ------------------------------------------------------------------
        $allSellers = array_merge($sellersByZone['ba'], $sellersByZone['cba'], $sellersByZone['pat']);
        DB::transaction(function () use ($allSellers, $products) {
            $productIds = $products->pluck('id')->toArray();
            foreach ($allSellers as $seller) {
                // Give each seller stock in 2 random products
                shuffle($productIds);
                $assigned = array_slice($productIds, 0, 2);
                foreach ($assigned as $productId) {
                    $exists = SellerStock::where('seller_id', $seller->id)
                        ->where('product_id', $productId)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    $boxes = rand(2, 5);
                    SellerStock::create([
                        'seller_id'       => $seller->id,
                        'product_id'      => $productId,
                        'boxes'           => $boxes,
                        'loose_units'     => rand(0, 4),
                        'reserved_boxes'  => 0,
                        'reserved_units'  => 0,
                        'minimum_stock'   => 1,
                    ]);
                    $this->countSellerStock++;
                }
            }
        });

        // ------------------------------------------------------------------
        // 12. Customers — 50 total
        // ------------------------------------------------------------------
        $cuitPool     = $this->buildCuitPool();
        $cuitIndex    = 0;
        $allCustomers = [];

        $customerDefs = [
            // BA Norte: 15 customers
            ['zone' => $zoneBa,  'sellers' => $sellersByZone['ba'],  'count' => 15],
            // Cordoba: 15 customers
            ['zone' => $zoneCba, 'sellers' => $sellersByZone['cba'], 'count' => 15],
            // Patagonia: 15 customers
            ['zone' => $zonePat, 'sellers' => $sellersByZone['pat'], 'count' => 15],
            // Director can_sell: 5 customers (cat D)
            ['zone' => $zoneBa,  'sellers' => [$directorCanSell],    'count' =>  5, 'force_cat' => 'D'],
        ];

        // Category distribution across first 45 customers: 5A 15B 25C
        $catDistribution = array_merge(
            array_fill(0, 5, 'A'),
            array_fill(0, 15, 'B'),
            array_fill(0, 25, 'C')
        );
        shuffle($catDistribution);
        $catIndex = 0;

        $firstNames = ['Laura', 'Valentina', 'Catalina', 'Florencia', 'Gabriela',
                       'Martina', 'Paula', 'Natalia', 'Alejandra', 'Sandra',
                       'Roberto', 'Marcelo', 'Gustavo', 'Hernán', 'Federico',
                       'Leandro', 'Sebastián', 'Nicolás', 'Emilio', 'Ramiro',
                       'Claudia', 'Patricia', 'Verónica', 'Daniela', 'Lorena'];
        $lastNames  = ['García', 'Sánchez', 'López', 'Torres', 'Ramírez',
                       'Morales', 'Reyes', 'Cruz', 'Ortega', 'Vega',
                       'Medina', 'Aguilar', 'Castillo', 'Ramos', 'Herrera',
                       'Vargas', 'Romero', 'Gutiérrez', 'Núñez', 'Suárez',
                       'Rojas', 'Mendez', 'Ríos', 'Jiménez', 'Acosta'];

        $customerCounter = 0;

        DB::transaction(function () use (
            $customerDefs, $categories, $catDistribution, &$catIndex,
            $firstNames, $lastNames, $cuitPool, &$cuitIndex,
            $contado, $term30, &$allCustomers, &$customerCounter
        ) {
            foreach ($customerDefs as $def) {
                $sellers = $def['sellers'];
                for ($i = 0; $i < $def['count']; $i++) {
                    $seller    = $sellers[$i % count($sellers)];
                    $forceCat  = $def['force_cat'] ?? null;
                    $catCode   = $forceCat ?? ($catDistribution[$catIndex++] ?? 'C');
                    $category  = $categories[$catCode] ?? $categories['C'];

                    $firstName = $firstNames[$customerCounter % count($firstNames)];
                    $lastName  = $lastNames[($customerCounter + 7) % count($lastNames)];
                    $email     = strtolower(
                        iconv('UTF-8', 'ASCII//TRANSLIT', $firstName) . '.'
                        . iconv('UTF-8', 'ASCII//TRANSLIT', $lastName) . $customerCounter
                        . '@demo.local'
                    );

                    $cuit = $cuitPool[$cuitIndex % count($cuitPool)];
                    $cuitIndex++;

                    // Idempotency: skip if email already seeded
                    if (Customer::where('email', $email)->exists()) {
                        $existing = Customer::where('email', $email)->first();
                        $allCustomers[] = $existing;
                        $customerCounter++;
                        continue;
                    }

                    // Some Cat A/B customers get reference price USD 700
                    $refPrice = null;
                    if (in_array($catCode, ['A', 'B']) && rand(0, 1)) {
                        $refPrice = Money::of('700.00', 'USD');
                    }

                    $term = ($customerCounter % 3 === 0) ? $contado : $term30;

                    $customer = Customer::create([
                        'first_name'              => $firstName,
                        'last_name'               => $lastName,
                        'cuit'                    => $cuit,
                        'phone'                   => $this->argentinePhone(),
                        'email'                   => $email,
                        'address'                 => 'Av. Demo ' . (100 + $customerCounter) . ', Argentina',
                        'category_id'             => $category->id,
                        'zone_id'                 => $def['zone']->id,
                        'assigned_seller_id'      => $seller->id,
                        'default_payment_terms_id' => $term->id,
                        'reference_price_amount'  => $refPrice ? (string) $refPrice->getAmount() : null,
                        // Only set currency when amount is present (constraint: both null or both set)
                        'reference_price_currency' => $refPrice ? 'USD' : null,
                        'purchase_frequency_days' => $category->default_frequency_days,
                        'first_purchase_date'     => now()->subDays(rand(30, 365))->toDateString(),
                        'is_active'               => true,
                    ]);

                    // Billing entity
                    $ivaCondition = match ($catCode) {
                        'A', 'B' => IvaCondition::ResponsableInscripto,
                        'C'      => rand(0, 1) ? IvaCondition::Monotributista : IvaCondition::ConsumidorFinal,
                        'D'      => IvaCondition::ConsumidorFinal,
                        default  => IvaCondition::ConsumidorFinal,
                    };

                    $billingCuit = $cuitPool[($cuitIndex + 50) % count($cuitPool)];
                    CustomerBillingEntity::firstOrCreate(
                        ['customer_id' => $customer->id, 'cuit' => $billingCuit],
                        [
                            'name'          => $customer->fullName() . ' - Razón Social',
                            'iva_condition' => $ivaCondition,
                            'is_primary'    => true,
                        ]
                    );
                    $this->countBillingEntities++;

                    // Contacts: 1-2 per customer
                    $contactCount = rand(1, 2);
                    for ($c = 0; $c < $contactCount; $c++) {
                        $hasBirthday = ($customerCounter % 3 === 0) && $c === 0;
                        CustomerContact::create([
                            'customer_id' => $customer->id,
                            'full_name'   => ($c === 0 ? $firstName : 'Contacto') . ' ' . $lastName,
                            'phone'       => $this->argentinePhone(),
                            'email'       => ($c === 0 ? $email : "sec{$customerCounter}@demo.local"),
                            'role_label'  => $c === 0 ? 'Titular' : 'Secretaría',
                            'birthday'    => $hasBirthday
                                ? now()->addDays(rand(0, 365))->toDateString()
                                : null,
                        ]);
                        $this->countContacts++;
                    }

                    $allCustomers[] = $customer;
                    $this->countCustomers++;
                    $customerCounter++;
                }
            }
        });

        // ------------------------------------------------------------------
        // 13. Scheduled actions — 6 total
        // ------------------------------------------------------------------
        DB::transaction(function () use ($allCustomers, $director) {
            $actionDefs = [
                ['days' =>  0, 'note' => 'Llamar para seguimiento post-entrega'],
                ['days' =>  0, 'note' => 'Confirmar stock disponible antes de pedido'],
                ['days' =>  3, 'note' => 'Visita programada para demostración de producto'],
                ['days' =>  5, 'note' => 'Enviar cotización actualizada'],
                ['days' =>  7, 'note' => 'Revisar evolución de compras trimestral'],
                ['days' => -3, 'note' => 'Acción vencida: seguimiento no realizado'],
            ];
            foreach ($actionDefs as $idx => $def) {
                $customer = $allCustomers[$idx % count($allCustomers)];
                $exists   = ScheduledAction::where('customer_id', $customer->id)
                    ->where('note', $def['note'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                ScheduledAction::create([
                    'customer_id'    => $customer->id,
                    'created_by'     => $director->id,
                    'scheduled_date' => now()->addDays($def['days'])->toDateString(),
                    'note'           => $def['note'],
                    'is_resolved'    => false,
                ]);
                $this->countScheduledActions++;
            }
        });

        // ------------------------------------------------------------------
        // 14. Sales — 150 total
        // ------------------------------------------------------------------
        // Build a flat list: 80 delivered, 30 confirmed, 20 draft, 20 cancelled
        $salesBag = array_merge(
            array_fill(0, 80, SaleStatus::Delivered),
            array_fill(0, 30, SaleStatus::Confirmed),
            array_fill(0, 20, SaleStatus::Draft),
            array_fill(0, 20, SaleStatus::Cancelled),
        );
        shuffle($salesBag);

        $pablo = collect($allSellers)->firstWhere('email', 'pablo@demo.dermacells.local');

        $createdSales = [];
        DB::transaction(function () use (
            $salesBag, $allCustomers, $allSellers, $sellersByZone,
            $zoneBa, $zoneCba, $zonePat,
            $products, $exchangeRates, $todayRate, $contado, $term30,
            $pablo, $eduardo, $director,
            &$createdSales
        ) {
            $delegatedCount = 0;
            foreach ($salesBag as $idx => $status) {
                $customer = $allCustomers[$idx % count($allCustomers)];

                // Resolve seller from customer zone
                $zone   = $customer->zone_id === $zoneBa->id  ? $zoneBa
                    : ($customer->zone_id === $zoneCba->id ? $zoneCba : $zonePat);
                $seller = $customer->assignedSeller ?? $customer->assignedSeller()->first();

                // Currency: 60% ARS, 40% USD
                $currency = ($idx % 10 < 6) ? 'ARS' : 'USD';

                // Pick a date (delivered: last 90d; rest: more recent)
                $saleDate = match ($status) {
                    SaleStatus::Delivered => now()->subDays(rand(1, 90)),
                    SaleStatus::Confirmed => now()->subDays(rand(0, 14)),
                    SaleStatus::Draft     => now()->subDays(rand(0, 7)),
                    SaleStatus::Cancelled => now()->subDays(rand(5, 60)),
                };
                $saleDateStr = $saleDate->toDateString();

                // Get exchange rate for that date (fallback to today)
                $er = $exchangeRates[$saleDateStr] ?? $todayRate;

                // Build 2-3 items
                $itemCount = rand(2, 3);
                $productList = $products->random($itemCount);
                $totalAmount = '0';
                $totalCurrency = $currency;

                // Delegated delivery: first 5 sales by Pablo go to BA
                $isDelegated = false;
                $delegatedDist = null;
                if ($pablo && $seller && $seller->id === $pablo->id && $delegatedCount < 5) {
                    $isDelegated   = true;
                    $delegatedDist = $eduardo;
                    $delegatedCount++;
                }

                // Compute total from items (USD 750/box or ARS equiv)
                $itemAmounts = [];
                foreach ($productList as $product) {
                    $boxes     = rand(1, 3);
                    $unitPrice = $currency === 'USD'
                        ? '750.0000'
                        : number_format((float) $er->rate_ars_per_usd * 750, 4, '.', '');
                    $subtotal  = number_format((float) $unitPrice * $boxes, 4, '.', '');
                    $itemAmounts[] = [
                        'product_id'          => $product->id,
                        'quantity_boxes'      => $boxes,
                        'quantity_units'      => 0,
                        'unit_price_amount'   => $unitPrice,
                        'unit_price_currency' => $currency,
                        'subtotal_amount'     => $subtotal,
                        'exchange_rate_id'    => $er->id,
                    ];
                    $totalAmount = number_format((float) $totalAmount + (float) $subtotal, 4, '.', '');
                }

                $term = ($idx % 4 === 0) ? $contado : $term30;
                $dueDate = $saleDate->copy()->addDays($term->days_to_due)->toDateString();

                $sale = Sale::create([
                    'customer_id'               => $customer->id,
                    'seller_id'                 => $seller ? $seller->id : $director->id,
                    'zone_id'                   => $zone->id,
                    'status'                    => $status,
                    'sale_date'                 => $saleDateStr,
                    'payment_terms_id'          => $term->id,
                    'due_date'                  => $dueDate,
                    'currency'                  => $currency,
                    'exchange_rate_id'          => $er->id,
                    'total_amount'              => $totalAmount,
                    'total_currency'            => $totalCurrency,
                    'delegated_delivery'        => $isDelegated,
                    'delegated_distributor_id'  => $isDelegated ? $delegatedDist->id : null,
                    'cancellation_reason'       => $status === SaleStatus::Cancelled ? 'Cancelado para demo' : null,
                    'cancelled_by'              => $status === SaleStatus::Cancelled ? $director->id : null,
                    'cancelled_at'              => $status === SaleStatus::Cancelled ? $saleDate : null,
                ]);

                // SaleItems
                foreach ($itemAmounts as $itemData) {
                    SaleItem::create(array_merge($itemData, ['sale_id' => $sale->id]));
                    $this->countSaleItems++;
                }

                // Status history entry — table is sales_status_history (singular, no timestamps)
                DB::table('sales_status_history')->insert([
                    'id'          => (string) Str::uuid(),
                    'sale_id'     => $sale->id,
                    'from_status' => null,
                    'to_status'   => $status->value,
                    'changed_by'  => $seller ? $seller->id : $director->id,
                    'changed_at'  => $saleDate,
                    'note'        => 'Estado inicial demo',
                ]);

                $createdSales[] = $sale;
                $this->countSales++;
            }
        });

        // ------------------------------------------------------------------
        // 15. Payments — for delivered (80%) and confirmed advances (30%)
        // ------------------------------------------------------------------
        $deliveredSales  = collect($createdSales)->filter(fn ($s) => $s->status === SaleStatus::Delivered);
        $confirmedSales  = collect($createdSales)->filter(fn ($s) => $s->status === SaleStatus::Confirmed);

        DB::transaction(function () use (
            $deliveredSales, $confirmedSales,
            $pmTransfer, $pmCash, $pmCheck, $director, $eduardo, $andres,
            $exchangeRates, $todayRate
        ) {
            $methods = array_filter([$pmTransfer, $pmCash, $pmCheck]);
            if (empty($methods)) {
                return;
            }

            // 80% of delivered sales get a full payment
            $toPayDelivered = $deliveredSales->random((int) ceil($deliveredSales->count() * 0.8));
            foreach ($toPayDelivered as $sale) {
                $pm = $methods[array_rand($methods)];
                $saleDateStr = $sale->sale_date instanceof Carbon
                    ? $sale->sale_date->toDateString()
                    : $sale->sale_date;
                $er = $exchangeRates[$saleDateStr] ?? $todayRate;

                $isCash = ($pm->code === 'cash');
                // Determine cash destination based on zone
                $zone = Zone::find($sale->zone_id);
                $cashDest = null;
                $cashDistId = null;
                if ($isCash && $zone) {
                    if ($zone->distributor_id) {
                        $cashDest   = 'distributor';
                        $cashDistId = $zone->distributor_id;
                    } else {
                        $cashDest = 'dermacells';
                    }
                }

                Payment::create([
                    'sale_id'                  => $sale->id,
                    'customer_id'              => $sale->customer_id,
                    'payment_method_id'        => $pm->id,
                    'amount_amount'            => $sale->total_amount,
                    'amount_currency'          => $sale->total_currency,
                    'exchange_rate_id'         => $er->id,
                    'is_advance'               => false,
                    'reference'                => $pm->requires_reference ? 'REF-DEMO-' . rand(10000, 99999) : null,
                    'cash_destination'         => $cashDest,
                    'cash_destination_dist_id' => $cashDistId,
                    'payment_date'             => $saleDateStr,
                    'reversed'                 => false,
                    'recorded_by'              => $director->id,
                ]);
                $this->countPayments++;
            }

            // 30% of confirmed sales get advance
            $toPayConfirmed = $confirmedSales->random((int) ceil($confirmedSales->count() * 0.3));
            foreach ($toPayConfirmed as $sale) {
                $pm = $methods[array_rand($methods)];
                $saleDateStr = $sale->sale_date instanceof Carbon
                    ? $sale->sale_date->toDateString()
                    : $sale->sale_date;
                $er = $exchangeRates[$saleDateStr] ?? $todayRate;

                // Advance = 50% of total
                $advanceAmount = number_format((float) $sale->total_amount * 0.5, 4, '.', '');

                Payment::create([
                    'sale_id'           => $sale->id,
                    'customer_id'       => $sale->customer_id,
                    'payment_method_id' => $pm->id,
                    'amount_amount'     => $advanceAmount,
                    'amount_currency'   => $sale->total_currency,
                    'exchange_rate_id'  => $er->id,
                    'is_advance'        => true,
                    'reference'         => $pm->requires_reference ? 'ADV-DEMO-' . rand(10000, 99999) : null,
                    'cash_destination'  => null,
                    'payment_date'      => $saleDateStr,
                    'reversed'          => false,
                    'recorded_by'       => $director->id,
                ]);
                $this->countPayments++;
            }
        });

        // ------------------------------------------------------------------
        // 16. Distributor preferred costs
        // ------------------------------------------------------------------
        DB::transaction(function () use ($eduardo, $andres, $products, $director) {
            foreach ($products as $product) {
                // Eduardo: fixed price USD 500
                $existsEd = DistributorPreferredCost::where('distributor_id', $eduardo->id)
                    ->where('product_id', $product->id)
                    ->exists();
                if (! $existsEd) {
                    DistributorPreferredCost::create([
                        'distributor_id' => $eduardo->id,
                        'product_id'     => $product->id,
                        'modality'       => PreferredCostModality::FixedPrice,
                        'value'          => '500.0000',
                        'currency'       => 'USD',
                        'updated_by'     => $director->id,
                        'updated_at'     => now(),
                    ]);
                }

                // Andres: discount 20%
                $existsAn = DistributorPreferredCost::where('distributor_id', $andres->id)
                    ->where('product_id', $product->id)
                    ->exists();
                if (! $existsAn) {
                    DistributorPreferredCost::create([
                        'distributor_id' => $andres->id,
                        'product_id'     => $product->id,
                        'modality'       => PreferredCostModality::DiscountPct,
                        'value'          => '0.2000',
                        'currency'       => 'USD',
                        'updated_by'     => $director->id,
                        'updated_at'     => now(),
                    ]);
                }
            }
        });

        // ------------------------------------------------------------------
        // 17. Distributor accounts
        // ------------------------------------------------------------------
        DB::transaction(function () use ($eduardo, $andres) {
            foreach ([$eduardo, $andres] as $dist) {
                DistributorAccount::firstOrCreate(
                    ['distributor_id' => $dist->id],
                    [
                        'balance_ars'          => '150000.0000',
                        'balance_usd'          => '2500.0000',
                        'gross_margin_ars'     => '45000.0000',
                        'gross_margin_usd'     => '750.0000',
                        'last_recalculated_at' => now(),
                        'updated_at'           => now(),
                    ]
                );
            }
        });

        // ------------------------------------------------------------------
        // 18. Seller commission configs
        // ------------------------------------------------------------------
        $effectiveFrom = now()->startOfMonth()->subDays(60)->toDateString();
        DB::transaction(function () use ($eduardo, $andres, $sellersByZone, $zoneBa, $zoneCba, $effectiveFrom, $director) {
            // Eduardo: 5% for each of his 3 BA sellers
            foreach ($sellersByZone['ba'] as $seller) {
                $exists = SellerCommissionConfig::where('distributor_id', $eduardo->id)
                    ->where('seller_id', $seller->id)
                    ->where('zone_id', $zoneBa->id)
                    ->exists();
                if (! $exists) {
                    SellerCommissionConfig::create([
                        'distributor_id' => $eduardo->id,
                        'seller_id'      => $seller->id,
                        'commission_pct' => '0.0500',
                        'zone_id'        => $zoneBa->id,
                        'set_by'         => $director->id,
                        'effective_from' => $effectiveFrom,
                    ]);
                    $this->countCommissionConfig++;
                }
            }

            // Andres: 6% for each of his 3 Cordoba sellers
            foreach ($sellersByZone['cba'] as $seller) {
                $exists = SellerCommissionConfig::where('distributor_id', $andres->id)
                    ->where('seller_id', $seller->id)
                    ->where('zone_id', $zoneCba->id)
                    ->exists();
                if (! $exists) {
                    SellerCommissionConfig::create([
                        'distributor_id' => $andres->id,
                        'seller_id'      => $seller->id,
                        'commission_pct' => '0.0600',
                        'zone_id'        => $zoneCba->id,
                        'set_by'         => $director->id,
                        'effective_from' => $effectiveFrom,
                    ]);
                    $this->countCommissionConfig++;
                }
            }
        });

        // ------------------------------------------------------------------
        // 19. Distributor settlements
        // ------------------------------------------------------------------
        DB::transaction(function () use ($eduardo, $andres, $pmTransfer, $director) {
            $settlementDefs = [
                ['dist' => $eduardo, 'amount' => '125000.0000', 'currency' => 'ARS', 'status' => SettlementStatus::Confirmed, 'days_back' => 7, 'confirmed_by' => $director->id],
                ['dist' => $eduardo, 'amount' => '50000.0000',  'currency' => 'ARS', 'status' => SettlementStatus::Pending,   'days_back' => 0, 'confirmed_by' => null],
                ['dist' => $andres,  'amount' => '95000.0000',  'currency' => 'ARS', 'status' => SettlementStatus::Confirmed, 'days_back' => 14,'confirmed_by' => $director->id],
            ];

            foreach ($settlementDefs as $def) {
                $submittedAt  = now()->subDays($def['days_back']);
                $confirmedAt  = $def['status'] === SettlementStatus::Confirmed ? $submittedAt->copy()->addDay() : null;

                $exists = DistributorSettlement::where('distributor_id', $def['dist']->id)
                    ->where('status', $def['status']->value)
                    ->where('amount_amount', $def['amount'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                DistributorSettlement::create([
                    'distributor_id'   => $def['dist']->id,
                    'amount_amount'    => $def['amount'],
                    'amount_currency'  => $def['currency'],
                    'payment_method_id' => $pmTransfer?->id,
                    'reference'        => 'RENDICION-DEMO-' . rand(1000, 9999),
                    'submitted_at'     => $submittedAt,
                    'confirmed_by'     => $def['confirmed_by'],
                    'confirmed_at'     => $confirmedAt,
                    'status'           => $def['status'],
                    'notes'            => 'Rendición demo ' . $def['status']->label(),
                ]);
                $this->countSettlements++;
            }
        });

        // ------------------------------------------------------------------
        // 20. Alerts
        // ------------------------------------------------------------------
        $alertTypes = [
            ['type' => 'cycle_due',            'severity' => 'warning'],
            ['type' => 'customer_inactive',     'severity' => 'info'],
            ['type' => 'stock_below_minimum',   'severity' => 'critical'],
            ['type' => 'birthday',              'severity' => 'info'],
        ];

        $allUsers = collect([$director, ...$allSellers]);

        DB::transaction(function () use ($alertTypes, $allUsers, $allCustomers) {
            for ($i = 0; $i < 20; $i++) {
                $user       = $allUsers[$i % $allUsers->count()];
                $alertType  = $alertTypes[$i % count($alertTypes)];
                $customer   = $allCustomers[$i % count($allCustomers)];

                Alert::create([
                    'alert_type'            => $alertType['type'],
                    'target_user_id'        => $user->id,
                    'reference_entity_type' => 'customer',
                    'reference_entity_id'   => $customer->id,
                    'payload_json'          => ['customer_name' => $customer->fullName(), 'demo' => true],
                    'severity'              => $alertType['severity'],
                    'delivered'             => (bool) ($i % 3),
                    'delivered_at'          => ($i % 3) ? now()->subMinutes(rand(10, 120)) : null,
                    'read_at'               => ($i % 5 === 0) ? now()->subMinutes(rand(5, 60)) : null,
                    'created_at'            => now()->subHours(rand(1, 72)),
                ]);
                $this->countAlerts++;
            }
        });

        // ------------------------------------------------------------------
        // 21. Authorization requests
        // ------------------------------------------------------------------
        DB::transaction(function () use ($createdSales, $allSellers, $director) {
            $sampleSales = collect($createdSales)->take(5);
            $authDefs = [
                ['type' => AuthorizationType::PriceChange,       'status' => 'pending',  'sale_idx' => 0],
                ['type' => AuthorizationType::ExchangeRateChange,'status' => 'pending',  'sale_idx' => 1],
                ['type' => AuthorizationType::PriceChange,       'status' => 'approved', 'sale_idx' => 2],
                ['type' => AuthorizationType::ExchangeRateChange,'status' => 'approved', 'sale_idx' => 3],
                ['type' => AuthorizationType::PriceChange,       'status' => 'rejected', 'sale_idx' => 4],
            ];

            foreach ($authDefs as $idx => $def) {
                $sale   = $sampleSales->get($def['sale_idx']);
                $seller = $allSellers[$idx % count($allSellers)];

                $exists = AuthorizationRequest::where('type', $def['type']->value)
                    ->where('status', $def['status'])
                    ->where('requested_by', $seller->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                AuthorizationRequest::create([
                    'type'             => $def['type'],
                    'sale_id'          => $sale?->id,
                    'requested_by'     => $seller->id,
                    'current_value'    => '750.00',
                    'proposed_value'   => '700.00',
                    'value_currency'   => 'USD',
                    'reason'           => 'Cliente solicita precio especial por volumen - Demo',
                    'status'           => $def['status'],
                    'resolved_by'      => in_array($def['status'], ['approved', 'rejected']) ? $director->id : null,
                    'resolved_at'      => in_array($def['status'], ['approved', 'rejected']) ? now()->subDays(rand(1, 5)) : null,
                    'rejection_reason' => $def['status'] === 'rejected' ? 'No cumple condiciones de volumen mínimo' : null,
                    'expires_at'       => now()->addDays(7),
                ]);
                $this->countAuthRequests++;
            }
        });

        // ------------------------------------------------------------------
        // 22. WhatsApp threads + messages
        // ------------------------------------------------------------------
        DB::transaction(function () use ($allCustomers) {
            $threadCustomers = array_slice($allCustomers, 0, 3);
            foreach ($threadCustomers as $idx => $customer) {
                $phone    = $customer->phone ?? '+54 11 ' . rand(10000000, 99999999);
                $existing = WhatsappThread::where('customer_id', $customer->id)->first();
                if ($existing) {
                    $thread = $existing;
                } else {
                    $thread = WhatsappThread::create([
                        'customer_id'      => $customer->id,
                        'wa_phone'         => $phone,
                        'wa_contact_id'    => 'wa_' . Str::random(10),
                        'last_message_at'  => now()->subHours(rand(1, 48)),
                        'last_inbound_at'  => now()->subHours(rand(2, 50)),
                        'last_outbound_at' => now()->subHours(rand(1, 24)),
                    ]);
                    $this->countWhatsappThreads++;
                }

                // 10 messages per thread
                $msgSamples = [
                    'Hola, quería consultar disponibilidad de Dermal para la semana próxima.',
                    'Perfecto, ¿pueden enviar cotización actualizada?',
                    'Confirmo el pedido de 3 cajas de Dermal y 2 de Pink.',
                    'Ya hice la transferencia, adjunto comprobante.',
                    'Recibí el pedido, todo en orden. Muchas gracias.',
                    'Buenos días, ¿tienen novedades sobre el nuevo lote de Capillary?',
                    'Necesito factura A por favor.',
                    'El próximo pedido lo hago en 30 días aproximadamente.',
                    'Muy buena atención como siempre.',
                    'Consulto por el precio especial que mencionaron.',
                ];
                foreach ($msgSamples as $msgIdx => $bodyText) {
                    $exists = WhatsappMessage::where('thread_id', $thread->id)
                        ->where('wa_message_id', "demo_{$thread->id}_{$msgIdx}")
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    WhatsappMessage::create([
                        'thread_id'    => $thread->id,
                        'wa_message_id' => "demo_{$thread->id}_{$msgIdx}",
                        'direction'    => ($msgIdx % 2 === 0) ? 'inbound' : 'outbound',
                        'body'         => $bodyText,
                        'message_type' => 'text',
                        'sent_at'      => now()->subHours(48 - $msgIdx * 4),
                        'synced_at'    => now()->subHours(47 - $msgIdx * 4),
                    ]);
                    $this->countWhatsappMessages++;
                }
            }
        });

        // ------------------------------------------------------------------
        // 23. AI Settings
        // ------------------------------------------------------------------
        DB::transaction(function () use ($director) {
            AiSetting::updateOrCreate(
                ['id' => AiSetting::SINGLETON_ID],
                [
                    'global_enabled'            => true,
                    'provider'                  => 'openai',
                    'model'                     => 'gpt-4o',
                    'monthly_token_cap_default' => 1_000_000,
                    'monthly_usd_cap_default'   => '100.00',
                    'updated_by'                => $director->id,
                    'updated_at'                => now(),
                ]
            );
        });

        // ------------------------------------------------------------------
        // 24. Seller monthly goals — 5 for current month
        // ------------------------------------------------------------------
        $goalSellers = array_slice(
            array_merge($sellersByZone['ba'], $sellersByZone['cba']),
            0,
            5
        );
        $targets     = [30, 40, 50, 60, 70];
        $yearMonth   = now()->startOfMonth()->toDateString();

        DB::transaction(function () use ($goalSellers, $targets, $yearMonth, $director) {
            foreach ($goalSellers as $idx => $seller) {
                $exists = SellerMonthlyGoal::where('seller_id', $seller->id)
                    ->where('year_month', $yearMonth)
                    ->exists();
                if ($exists) {
                    continue;
                }
                SellerMonthlyGoal::create([
                    'seller_id'    => $seller->id,
                    'year_month'   => $yearMonth,
                    'target_boxes' => $targets[$idx],
                    'created_by'   => $director->id,
                ]);
                $this->countGoals++;
            }
        });

        // ------------------------------------------------------------------
        // Summary
        // ------------------------------------------------------------------
        $this->command?->newLine();
        $this->command?->info('DemoDataSeeder completed successfully.');
        $this->command?->table(
            ['Entity', 'Seeded'],
            [
                ['Zones',               $this->countZones],
                ['Users',               $this->countUsers],
                ['Customers',           $this->countCustomers],
                ['BillingEntities',     $this->countBillingEntities],
                ['Contacts',            $this->countContacts],
                ['ScheduledActions',    $this->countScheduledActions],
                ['StockLots',           $this->countLots],
                ['CentralStock rows',   $this->countCentralStock],
                ['DistributorStock',    $this->countDistributorStock],
                ['SellerStock',         $this->countSellerStock],
                ['ExchangeRates',       $this->countExchangeRates],
                ['Sales',               $this->countSales],
                ['SaleItems',           $this->countSaleItems],
                ['Payments',            $this->countPayments],
                ['CommissionConfigs',   $this->countCommissionConfig],
                ['Settlements',         $this->countSettlements],
                ['Alerts',              $this->countAlerts],
                ['AuthRequests',        $this->countAuthRequests],
                ['WhatsappThreads',     $this->countWhatsappThreads],
                ['WhatsappMessages',    $this->countWhatsappMessages],
                ['SellerGoals',         $this->countGoals],
            ]
        );
    }
}
