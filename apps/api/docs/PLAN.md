# CRM Dermacells — Implementation Plan

## Phase 13 — AI Assistant Module (§11)

| Section | Feature | Status |
|---------|---------|--------|
| §11.1 | Global AI toggle + per-user override (AiSetting, AiUserOverride) | Done |
| §11.2 | SuggestNextActionForCustomer — structured next action | Done |
| §11.2 | GenerateDailyDigest — morning brief (cached 24h) | Done |
| §11.2 | AskNaturalLanguageQuestion — streaming Q&A | Done |
| §11.3 | Distributor-scope AI features (zone risk, seller activity) | Done |
| §11.4 | Director: AI-assisted customer reassignment suggestions | **Done** |
| §11.5 | LLM client factory (OpenAI + Anthropic), LeakGuard, token monitor | Done |

### §11.4 — Customer Reassignment Suggestions (covered)

Files added in this task:

- `app/Domain/AI/UseCases/SuggestCustomerReassignments.php` — Director-only use case.
  Queries last-90d seller performance, flags underperformers, builds candidate list,
  calls LLM with structured output schema, applies LeakGuard post-filter.
- `app/Services/Customers/CustomerReassignmentService.php` — applies a single
  AI-suggested reassignment inside a DB transaction; writes an `audit_logs` entry.
- `app/Http/Controllers/Api/V1/AIController.php` — added `suggestReassignments()`
  method; route registered at `POST /api/v1/ai/reassignments/suggest` inside
  the `ai.cap` middleware group.
- `app/Filament/Pages/AiReassignmentSuggestions.php` — Director-only Filament page
  with zone/limit form, "Obtener sugerencias" action, results table, and inline
  "Aplicar" action that calls `CustomerReassignmentService::reassignSingle()`.
- `resources/views/filament/pages/ai-reassignment-suggestions.blade.php` — Blade
  view for the Filament page.
- `tests/Feature/Phase13/SuggestReassignmentsTest.php` — 4 Pest tests covering
  Director happy path, Seller 403, LeakGuard foreign-ID rejection, and audit log.
