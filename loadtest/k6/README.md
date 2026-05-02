# CRM Dermacells — k6 Load Tests

Load tests for Phase 17 verification (PLAN.md). All tests must pass before production go-live.

## Prerequisites

Install k6:

```bash
# macOS
brew install k6

# Linux
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6
```

## Obtaining test tokens

Generate Sanctum Personal Access Tokens for each role on the staging environment:

```bash
# From apps/api on the staging server (or via artisan ssh):
php artisan tinker --no-interaction <<'PHP'
$seller = App\Models\User::where('role', 'seller')->first();
$dist   = App\Models\User::where('role', 'distributor')->first();
$dir    = App\Models\User::where('role', 'director')->first();

echo "SELLER_TOKEN="  . $seller->createToken('k6-test', ['*'], now()->addHours(2))->plainTextToken . "\n";
echo "DISTRIBUTOR_TOKEN=" . $dist->createToken('k6-test', ['*'], now()->addHours(2))->plainTextToken . "\n";
echo "DIRECTOR_TOKEN=" . $dir->createToken('k6-test', ['*'], now()->addHours(2))->plainTextToken . "\n";
PHP
```

## Running the dashboard flow test

```bash
k6 run --vus 50 --duration 5m k6/dashboard-flow.js \
    -e BASE_URL=https://staging.dermacells.com.ar \
    -e SELLER_TOKEN=<token_from_above> \
    -e DISTRIBUTOR_TOKEN=<token_from_above> \
    -e DIRECTOR_TOKEN=<token_from_above>
```

## Running the auth storm test

Requires staging to have `LOAD_TEST_AUTH_ENABLED=true` in its environment:

```bash
k6 run --vus 25 --duration 2m k6/auth-flow.js \
    -e BASE_URL=https://staging.dermacells.com.ar \
    -e TEST_USER_EMAIL=k6-seller@dermacells.com.ar \
    -e LOAD_TEST_KEY=<staging_load_test_key>
```

## Thresholds

| Metric | Threshold | Alarm |
|---|---|---|
| p95 response time | < 800ms | Fail test + block deploy |
| Error rate | < 1% | Fail test + block deploy |
| Login p95 | < 500ms | Fail auth test |

## Reading results

k6 prints a summary at the end. Key metrics to review:

- `http_req_duration` — overall latency distribution
- `dashboard_response_ms` — dashboard endpoint specifically
- `write_response_ms` — create sale / register payment
- `error_rate` — fraction of failed requests

## CI integration

The GitHub Actions `release.yml` workflow runs the dashboard flow test against staging before promoting to production. The job fails if any threshold is breached, blocking the production deploy.

## Notes

- Tokens expire after 2 hours. Re-generate before each test run.
- Do NOT run load tests against production.
- The auth-flow test requires `LOAD_TEST_AUTH_ENABLED=true` which must never be set in production.
- Test data (draft sales, payments) created by k6 should be cleaned up with `php artisan db:seed --class=CleanK6TestDataSeeder` after each run.
