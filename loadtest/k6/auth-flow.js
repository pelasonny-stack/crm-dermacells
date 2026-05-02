/**
 * CRM Dermacells — Auth (Sanctum) Login Storm Test
 *
 * Simulates a burst of concurrent login attempts using the Sanctum SPA
 * cookie flow. This validates the auth stack can handle simultaneous
 * session initiations without lock contention or token exhaustion.
 *
 * Flow per VU:
 *   1. GET /sanctum/csrf-cookie  → receive XSRF-TOKEN cookie
 *   2. POST /auth/google/callback (mobile code exchange) → receive PAT
 *   3. GET /api/v1/dashboards/me with PAT → validate session works
 *   4. DELETE /auth/token → logout
 *
 * NOTE: This test mocks the OAuth exchange by hitting a test-only endpoint
 * /api/v1/auth/test-token that must be enabled ONLY in staging with
 * APP_ENV=staging and LOAD_TEST_AUTH_ENABLED=true. Never enable in production.
 *
 * Usage:
 *   k6 run --vus 25 --duration 2m k6/auth-flow.js \
 *       -e BASE_URL=https://staging.dermacells.com.ar \
 *       -e TEST_USER_EMAIL=k6-seller@dermacells.com.ar
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const loginErrorRate  = new Rate('login_error_rate');
const loginTrend      = new Trend('login_duration_ms', true);
const csrfTrend       = new Trend('csrf_duration_ms', true);

export const options = {
  scenarios: {
    login_storm: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 25 },
        { duration: '1m',  target: 25 },
        { duration: '30s', target: 0  },
      ],
    },
  },

  thresholds: {
    login_error_rate:  ['rate<0.01'],
    login_duration_ms: ['p(95)<500'],  // Login must be fast
    http_req_duration: ['p(95)<600'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

function getCsrfToken(jar) {
  const res = http.get(`${BASE_URL}/sanctum/csrf-cookie`, {
    cookies: jar,
    headers: { 'Accept': 'application/json' },
  });

  csrfTrend.add(res.timings.duration);

  check(res, {
    'csrf cookie: 204 or 200': (r) => r.status === 204 || r.status === 200,
    'csrf cookie: XSRF-TOKEN set': (r) => {
      const cookies = r.cookies['XSRF-TOKEN'];
      return cookies !== undefined && cookies.length > 0;
    },
  });

  // Extract XSRF-TOKEN from cookies
  const xsrfCookie = res.cookies['XSRF-TOKEN'];
  return xsrfCookie ? decodeURIComponent(xsrfCookie[0].value) : '';
}

function obtainTestToken() {
  // Uses the staging-only test auth endpoint.
  // This endpoint validates LOAD_TEST_AUTH_ENABLED=true before responding.
  const email = __ENV.TEST_USER_EMAIL || 'k6-seller@dermacells.com.ar';

  const res = http.post(
    `${BASE_URL}/api/v1/auth/test-token`,
    JSON.stringify({ email, role: 'seller', ttl_seconds: 300 }),
    {
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Load-Test-Key': __ENV.LOAD_TEST_KEY || 'k6-test-key',
      },
    }
  );

  loginTrend.add(res.timings.duration);

  const ok = check(res, {
    'test-token: 200': (r) => r.status === 200,
    'test-token: has token': (r) => {
      try {
        return JSON.parse(r.body).token !== undefined;
      } catch {
        return false;
      }
    },
  });

  loginErrorRate.add(!ok);

  if (!ok) {
    return null;
  }

  try {
    return JSON.parse(res.body).token;
  } catch {
    return null;
  }
}

function validateSession(token) {
  const res = http.get(`${BASE_URL}/api/v1/dashboards/me`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json',
    },
  });

  return check(res, {
    'session valid: 200': (r) => r.status === 200,
    'session valid: has data': (r) => r.body.length > 10,
  });
}

function revokeToken(token) {
  const res = http.del(`${BASE_URL}/auth/token`, null, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json',
    },
  });

  check(res, {
    'revoke: 200': (r) => r.status === 200,
  });
}

export default function () {
  // Step 1: CSRF (only relevant for SPA cookie flow — included for completeness)
  const jar = http.cookieJar();
  getCsrfToken(jar);
  sleep(randomIntBetween(1, 2));

  // Step 2: Obtain PAT via staging test endpoint
  const token = obtainTestToken();
  if (!token) {
    sleep(5);
    return;
  }
  sleep(randomIntBetween(1, 2));

  // Step 3: Validate the session works
  validateSession(token);
  sleep(randomIntBetween(2, 4));

  // Step 4: Logout
  revokeToken(token);
  sleep(randomIntBetween(1, 2));
}

export function handleSummary(data) {
  const p95Login   = data.metrics.login_duration_ms?.values?.['p(95)'] ?? 0;
  const errRate    = (data.metrics.login_error_rate?.values?.rate ?? 0) * 100;
  const totalLogins = data.metrics.http_reqs?.values?.count ?? 0;

  console.log(`\n=== CRM Dermacells Auth Storm Summary ===`);
  console.log(`  p95 login time : ${p95Login.toFixed(0)}ms  (threshold: <500ms) ${p95Login < 500 ? 'PASS' : 'FAIL'}`);
  console.log(`  Login error rate: ${errRate.toFixed(2)}%  (threshold: <1%)     ${errRate < 1 ? 'PASS' : 'FAIL'}`);
  console.log(`  Total requests : ${totalLogins}`);
  console.log(`=========================================\n`);

  return { stdout: JSON.stringify(data, null, 2) };
}
