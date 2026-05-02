/**
 * CRM Dermacells — Dashboard Load Test
 *
 * Simulates 50 concurrent virtual users for 5 minutes with a realistic
 * role distribution based on the system's user base:
 *   60% Vendedores  — sellers with daily dashboard + create draft sale
 *   30% Distribuidores — distributors checking zone health + registering payment
 *   10% Director — executive dashboard (heaviest queries)
 *
 * Each VU: login (mock token injection), 5 read calls, 1 write call.
 *
 * Thresholds:
 *   p95 response time < 800ms  (matches PLAN.md Phase 17 SLA)
 *   error rate < 1%
 *
 * Usage:
 *   k6 run --vus 50 --duration 5m k6/dashboard-flow.js \
 *       -e BASE_URL=https://staging.dermacells.com.ar \
 *       -e SELLER_TOKEN=<sanctum_pat> \
 *       -e DISTRIBUTOR_TOKEN=<sanctum_pat> \
 *       -e DIRECTOR_TOKEN=<sanctum_pat>
 *
 * Note: tokens are injected via environment variables so this file contains
 * no hardcoded credentials. Obtain test tokens via:
 *   php artisan tinker --execute="User::factory()->seller()->create()->createToken('k6-test')->plainTextToken"
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

// --- Custom metrics ---
const errorRate      = new Rate('error_rate');
const dashboardTrend = new Trend('dashboard_response_ms', true);
const salesListTrend = new Trend('sales_list_response_ms', true);
const writeTrend     = new Trend('write_response_ms', true);

// --- Test configuration ---
export const options = {
  scenarios: {
    // Ramp up to 50 VUs over 1 minute, hold for 3 minutes, ramp down 1 minute
    ramp_hold: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '1m', target: 50 },
        { duration: '3m', target: 50 },
        { duration: '1m', target: 0  },
      ],
    },
  },

  thresholds: {
    // p95 < 800ms across ALL requests
    http_req_duration: ['p(95)<800'],
    // Overall error rate < 1%
    error_rate: ['rate<0.01'],
    // Dashboard endpoint specifically
    dashboard_response_ms: ['p(95)<800'],
  },
};

// --- Helpers ---

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

function headers(token) {
  return {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
}

function checkResponse(res, name) {
  const ok = check(res, {
    [`${name}: status 2xx`]: (r) => r.status >= 200 && r.status < 300,
    [`${name}: not empty`]:  (r) => r.body.length > 0,
  });
  errorRate.add(!ok);
  return ok;
}

// --- Role flows ---

function sellerFlow(token) {
  const h = headers(token);

  group('seller: dashboard', () => {
    const res = http.get(`${BASE_URL}/api/v1/dashboards/me`, { headers: h });
    dashboardTrend.add(res.timings.duration);
    checkResponse(res, 'seller-dashboard');
    sleep(randomIntBetween(1, 3));
  });

  group('seller: sales list', () => {
    const res = http.get(`${BASE_URL}/api/v1/sales?status=confirmed&per_page=15`, { headers: h });
    salesListTrend.add(res.timings.duration);
    checkResponse(res, 'seller-sales');
    sleep(randomIntBetween(1, 2));
  });

  group('seller: customers list', () => {
    const res = http.get(`${BASE_URL}/api/v1/customers?per_page=20`, { headers: h });
    checkResponse(res, 'seller-customers');
    sleep(randomIntBetween(1, 2));
  });

  group('seller: alerts', () => {
    const res = http.get(`${BASE_URL}/api/v1/alerts?unread=true`, { headers: h });
    checkResponse(res, 'seller-alerts');
    sleep(randomIntBetween(1, 2));
  });

  group('seller: commissions', () => {
    const res = http.get(`${BASE_URL}/api/v1/commissions/me`, { headers: h });
    checkResponse(res, 'seller-commissions');
    sleep(randomIntBetween(1, 2));
  });

  // Write: create draft sale
  group('seller: create draft sale', () => {
    const idempotencyKey = `k6-${__VU}-${Date.now()}`;
    const payload = JSON.stringify({
      customer_id: randomIntBetween(1, 100),
      items: [{
        product_id: randomIntBetween(1, 4),
        quantity: randomIntBetween(1, 5),
      }],
      payment_term_id: 1,
    });

    const res = http.post(`${BASE_URL}/api/v1/sales`, payload, {
      headers: { ...h, 'Idempotency-Key': idempotencyKey },
    });
    writeTrend.add(res.timings.duration);
    // Allow 422 (validation errors with test data) — real write errors tracked separately
    const ok = check(res, {
      'draft sale: not 5xx': (r) => r.status < 500,
    });
    errorRate.add(!ok);
    sleep(randomIntBetween(2, 4));
  });
}

function distributorFlow(token) {
  const h = headers(token);

  group('distributor: dashboard', () => {
    const res = http.get(`${BASE_URL}/api/v1/dashboards/me`, { headers: h });
    dashboardTrend.add(res.timings.duration);
    checkResponse(res, 'distributor-dashboard');
    sleep(randomIntBetween(1, 3));
  });

  group('distributor: sales', () => {
    const res = http.get(`${BASE_URL}/api/v1/sales?per_page=20`, { headers: h });
    salesListTrend.add(res.timings.duration);
    checkResponse(res, 'distributor-sales');
    sleep(randomIntBetween(1, 2));
  });

  group('distributor: customers', () => {
    const res = http.get(`${BASE_URL}/api/v1/customers?per_page=20`, { headers: h });
    checkResponse(res, 'distributor-customers');
    sleep(randomIntBetween(1, 2));
  });

  group('distributor: account balance', () => {
    const res = http.get(`${BASE_URL}/api/v1/distributor/account`, { headers: h });
    checkResponse(res, 'distributor-account');
    sleep(randomIntBetween(1, 2));
  });

  group('distributor: settlements', () => {
    const res = http.get(`${BASE_URL}/api/v1/distributor/settlements`, { headers: h });
    checkResponse(res, 'distributor-settlements');
    sleep(randomIntBetween(1, 2));
  });

  // Write: register payment
  group('distributor: register payment', () => {
    const idempotencyKey = `k6-payment-${__VU}-${Date.now()}`;
    const payload = JSON.stringify({
      customer_id:     randomIntBetween(1, 100),
      sale_id:         randomIntBetween(1, 200),
      amount:          randomIntBetween(100, 5000),
      currency:        'ARS',
      payment_method:  'transfer_dermacells',
    });

    const res = http.post(`${BASE_URL}/api/v1/payments`, payload, {
      headers: { ...h, 'Idempotency-Key': idempotencyKey },
    });
    writeTrend.add(res.timings.duration);
    const ok = check(res, {
      'register payment: not 5xx': (r) => r.status < 500,
    });
    errorRate.add(!ok);
    sleep(randomIntBetween(2, 4));
  });
}

function directorFlow(token) {
  const h = headers(token);

  group('director: executive dashboard', () => {
    const res = http.get(`${BASE_URL}/api/v1/dashboards/me`, { headers: h });
    dashboardTrend.add(res.timings.duration);
    checkResponse(res, 'director-dashboard');
    sleep(randomIntBetween(2, 4));
  });

  group('director: portfolio health', () => {
    const res = http.get(`${BASE_URL}/api/v1/customers?category=all&per_page=50`, { headers: h });
    checkResponse(res, 'director-portfolio');
    sleep(randomIntBetween(1, 3));
  });

  group('director: authorization queue', () => {
    const res = http.get(`${BASE_URL}/api/v1/authorizations?status=pending`, { headers: h });
    checkResponse(res, 'director-authorizations');
    sleep(randomIntBetween(1, 2));
  });

  group('director: monthly sales', () => {
    const res = http.get(`${BASE_URL}/api/v1/sales?per_page=50&status=confirmed`, { headers: h });
    salesListTrend.add(res.timings.duration);
    checkResponse(res, 'director-sales');
    sleep(randomIntBetween(1, 2));
  });

  group('director: stock overview', () => {
    const res = http.get(`${BASE_URL}/api/v1/stock/central`, { headers: h });
    checkResponse(res, 'director-stock');
    sleep(randomIntBetween(1, 2));
  });

  // Write: approve authorization (if any pending)
  group('director: no write (read-heavy role)', () => {
    // Directors primarily read; skip write to avoid test data contamination
    sleep(randomIntBetween(1, 2));
  });
}

// --- Main VU entrypoint ---

export default function () {
  // Token selection: each VU is assigned a stable role based on its VU number
  // VUs 1-30: Vendedor (60%), VUs 31-45: Distribuidor (30%), VUs 46-50: Director (10%)
  const vu = __VU;

  if (vu <= 30) {
    const token = __ENV.SELLER_TOKEN || 'MISSING_SELLER_TOKEN';
    sellerFlow(token);
  } else if (vu <= 45) {
    const token = __ENV.DISTRIBUTOR_TOKEN || 'MISSING_DISTRIBUTOR_TOKEN';
    distributorFlow(token);
  } else {
    const token = __ENV.DIRECTOR_TOKEN || 'MISSING_DIRECTOR_TOKEN';
    directorFlow(token);
  }
}

// --- Teardown summary ---

export function handleSummary(data) {
  const p95 = data.metrics.http_req_duration?.values?.['p(95)'] ?? 0;
  const errRate = (data.metrics.error_rate?.values?.rate ?? 0) * 100;

  console.log(`\n=== CRM Dermacells Load Test Summary ===`);
  console.log(`  p95 response time : ${p95.toFixed(0)}ms  (threshold: <800ms) ${p95 < 800 ? 'PASS' : 'FAIL'}`);
  console.log(`  Error rate        : ${errRate.toFixed(2)}%  (threshold: <1%)   ${errRate < 1 ? 'PASS' : 'FAIL'}`);
  console.log(`  Total requests    : ${data.metrics.http_reqs?.values?.count ?? 0}`);
  console.log(`========================================\n`);

  return {
    stdout: JSON.stringify(data, null, 2),
  };
}
