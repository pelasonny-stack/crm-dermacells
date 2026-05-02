# CRM Dermacells — 2FA Enforcement Guide (Phase 17)

Per PLAN.md Phase 17 and the security spec (§18), all Director-role users must authenticate
with multi-factor authentication (MFA/2FA) before accessing the CRM. This document covers:

1. Google Workspace admin configuration
2. Microsoft Entra (Azure AD) Conditional Access policy
3. Backend enforcement via `amr` claim inspection in `OAuthController`

---

## 1. Google Workspace — Enforce 2-Step Verification for Director group

### Prerequisites

- Google Workspace admin access (Super Admin or Security Admin role)
- A Google Group or OU containing all Director accounts

### Steps

1. Sign in to [admin.google.com](https://admin.google.com).
2. Navigate to **Security > Authentication > 2-step verification**.
3. Select **Enforcement > Turn on enforcement**.
4. Under **Apply to**, choose the Director group or OU.
5. Set **Enforcement period** to **Immediate** (do not give a grace period for Directors).
6. Set **Allowed methods** to:
   - Hardware security key (preferred for Directors)
   - Google Authenticator / Authenticator app
   - Google prompt (acceptable fallback)
   - **Disable** SMS — SMS 2FA is phishable and does not satisfy strong MFA requirements.
7. Click **Save**.
8. Verify by attempting to log in as a Director user from an incognito window — the account should prompt for 2FA before OAuth consent.

### Testing

```bash
# Simulate an OAuth flow without 2FA (should be blocked by Google at consent screen)
# There is no way to programmatically bypass this — verify manually in the browser.
```

---

## 2. Microsoft Entra (Azure AD) — Conditional Access Policy for CRM SP

### Prerequisites

- Microsoft Entra ID P1 or P2 licence (Conditional Access requires P1 minimum)
- Global Administrator or Conditional Access Administrator role
- The CRM application registered in Entra as a Service Principal (SP)

### Steps

1. Go to [entra.microsoft.com](https://entra.microsoft.com) → **Protection > Conditional Access > Policies**.
2. Click **+ New policy**.
3. Name: `Require MFA for CRM Dermacells — Directors`.
4. **Assignments > Users and groups**: Select the Director security group.
5. **Assignments > Target resources**: Select the CRM app SP (search `crm.dermacells.com.ar` or the App ID).
6. **Access controls > Grant**: Select **Require multifactor authentication**. Click **Select**.
7. **Session**: Leave defaults (no session controls needed — Sanctum handles session lifetime).
8. **Enable policy**: Set to **On**.
9. Click **Create**.

### Verifying the `amr` claim

After the policy is active, Director login tokens will include an `amr` (Authentication Methods References) claim with values such as:

```json
{
  "amr": ["mfa", "pwd", "rsa"]
}
```

The presence of `"mfa"` in the `amr` array confirms the user completed multi-factor authentication. The backend checks this claim (see Section 3).

---

## 3. Backend Enforcement — `amr` Claim Check in OAuthController

The `OAuthController` already handles the OAuth callback. The following patch adds an
env-gated check: when `OAUTH_REQUIRE_MFA=true`, Director logins without `amr=mfa` are
rejected with a 403 `OAUTH_MFA_REQUIRED` response.

### Configuration

Add to Secrets Manager / `.env.example`:

```
OAUTH_REQUIRE_MFA=false   # Set true in production after IdP policies are active
```

Add to `config/auth.php` (or `config/services.php`):

```php
'oauth_require_mfa' => (bool) env('OAUTH_REQUIRE_MFA', false),
```

### Code patch — add to `OAuthController::callback()` after `$subError` check

The patch is applied in `app/Http/Controllers/Auth/OAuthController.php`, immediately before
the `$isMobile` branch that issues the token or starts the session:

```php
// --- 2FA enforcement for Director role (Phase 17) ---
// When OAUTH_REQUIRE_MFA=true, Director users MUST have completed MFA at the
// IdP before we issue a session or PAT. We verify this by inspecting the `amr`
// (Authentication Methods References) claim in the raw OAuth response.
//
// Google Workspace: the `amr` claim is not present by default. Google signals
// 2FA completion via the `acr` claim with value "http://schemas.openid.net/pke"
// or by including "otp" in the raw token. Check both.
//
// Microsoft Entra: includes `amr: ["mfa", ...]` when Conditional Access MFA
// is satisfied.
//
// IMPORTANT: Only enable OAUTH_REQUIRE_MFA=true AFTER confirming the IdP
// policies are active. Enabling before will lock out all Directors.
if (config('auth.oauth_require_mfa', false) && $user->getAttribute('role') === 'director') {
    $raw = method_exists($oauthUser, 'getRaw') ? $oauthUser->getRaw() : [];
    $amr = (array) ($raw['amr'] ?? []);

    // Google uses the token's `acr` field or `amr` with "totp"/"otp" values.
    $googleMfaClaimed = isset($raw['acr']) && str_contains((string) $raw['acr'], 'pke')
        || in_array('otp', $amr, true)
        || in_array('totp', $amr, true);

    // Microsoft includes 'mfa' literally in the amr array.
    $microsoftMfaClaimed = in_array('mfa', $amr, true)
        || in_array('rsa', $amr, true);

    $mfaCompleted = match ($provider) {
        'google'    => $googleMfaClaimed,
        'microsoft' => $microsoftMfaClaimed,
        default     => false,
    };

    if (! $mfaCompleted) {
        Log::warning('OAuth MFA enforcement: Director login rejected — no MFA amr claim', [
            'provider' => $provider,
            'user_id'  => $user->getKey(),
            'amr'      => $amr,
        ]);

        return response()->json([
            'code'    => 'OAUTH_MFA_REQUIRED',
            'message' => 'Los Directores deben autenticarse con verificación en dos pasos. Configure 2FA en su cuenta y reintente.',
        ], Response::HTTP_FORBIDDEN);
    }
}
// --- end 2FA enforcement ---
```

### Important notes

- **Deploy order**: Enable IdP policies FIRST, wait 24h for all Directors to enrol, then set `OAUTH_REQUIRE_MFA=true` in Secrets Manager.
- **Fallback**: If a Director is locked out (e.g., lost 2FA device), a Workspace/Entra admin must reset their MFA enrolment at the IdP level. The CRM has no bypass.
- **Mobile flow**: The same check applies to the mobile OAuth path (Expo `expo-auth-session`). Since both Google and Microsoft Entra propagate `amr` claims to the authorization code flow, the check works identically.
- **Testing**: In staging, set `OAUTH_REQUIRE_MFA=false` to avoid breaking CI login flows with test accounts that may not have 2FA enrolled.

---

## 4. Go-Live Checklist

- [ ] Google Workspace: 2-Step Verification enforcement active for Director OU/group
- [ ] Microsoft Entra: Conditional Access policy `Require MFA for CRM Dermacells` is **ON**
- [ ] All Director accounts have enrolled at least 2 MFA methods (primary + backup)
- [ ] `OAUTH_REQUIRE_MFA=true` set in `dermacells/production` Secrets Manager
- [ ] Manual test: Director login without 2FA → rejected with `OAUTH_MFA_REQUIRED`
- [ ] Manual test: Director login with 2FA → accepted, session/token issued
- [ ] Vendedor/Distribuidor login without 2FA → accepted (no 2FA requirement for non-Directors)
