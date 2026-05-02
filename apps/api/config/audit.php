<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit Log Configuration
|--------------------------------------------------------------------------
|
| Per §16.13 of the Dermacells spec, every mutation captured by the
| AuditObserver is hashed in an HMAC-SHA256 chain so any tampering can
| be detected by the nightly `audit:verify` command.
|
| The HMAC key MUST be supplied via AWS Secrets Manager in production
| (Phase 1, item 7 of PLAN.md). It is loaded at boot time and is the
| only secret required by the AuditObserver and VerifyAuditChain command.
|
| Rotating the key invalidates the historical chain — never rotate without
| first archiving the existing audit_log and starting a fresh chain.
|
*/

return [
    'hmac_key' => env('AUDIT_HMAC_KEY'),
];
