# AI_OPPORTUNITIES.md

> Brainstorm de capacidades AI que pueden diferenciar CRM Dermacells globalmente, partiendo de la base ya construida en Phase 13 (provider-configurable LLM, prompt caching, leak guard, token cap, structured output, SSE).
>
> Audiencia: Director Ejecutivo + equipo de producto.
> Confianza: Media-Alta. Modelos citados son los disponibles a 2026-05; los costos son estimados al alza.
> Gaps: ningún benchmark interno todavía; cifras de adopción son hipotéticas; latencias de Whisper Turbo on-device asumidas para iPhone 14+.

---

## 1. Diez features rankeadas por impacto x novedad x feasibility

Score: cada eje de 1 a 5; total = producto / 25 normalizado.

| # | Feature | Impacto | Novedad | Feasibility | Score | Tech |
|---|---------|---------|---------|-------------|-------|------|
| 1 | Order parsing agent (NL -> Sale draft) | 5 | 4 | 4 | 80 | Sonnet 4.6 + tools + product fuzzy match |
| 2 | Daily worklist auto-priorizado por Vendedor | 5 | 3 | 5 | 75 | Cron + Sonnet 4.6 structured output |
| 3 | Photo-of-receipt -> reconciliation Xubio | 4 | 5 | 4 | 80 | GPT-5 Vision o Claude vision + OCR fallback |
| 4 | WhatsApp voice note -> structured action | 4 | 4 | 5 | 80 | Whisper Turbo + Sonnet 4.6 |
| 5 | Churn prediction per cliente (`churn_score`) | 5 | 3 | 4 | 60 | XGBoost on PG + features de evolution_state |
| 6 | Customer recovery agent (multi-step) | 5 | 5 | 3 | 75 | Sonnet 4.6 + tool catalogue + state machine |
| 7 | Conversational filters en tableros | 4 | 3 | 5 | 60 | Sonnet 4.6 function calling -> SQL safe-builder |
| 8 | Lot expiry vs demand recommender | 4 | 4 | 4 | 64 | Solver (greedy) + demand model |
| 9 | Inline upsell suggester en form de Sale | 4 | 3 | 5 | 60 | Sonnet 4.6 con context cache + per-customer prior |
| 10 | Anomaly detector de operaciones (cron) | 3 | 3 | 5 | 45 | Isolation forest + LLM explainer |

Notas de ranking:
- "Order parsing agent" es el #1 porque ataca la friccion #1 del Vendedor (cargar pedido en mobile) y nadie en CRM LATAM lo tiene production-grade.
- "Customer recovery agent" tiene impacto altisimo pero feasibility 3 por requerir tool catalogue, state machine y human-in-the-loop UX bien resuelto (ver seccion 4).
- Anomaly detector queda bajo porque ya hay alertas reglas-based en Phase 10; el delta marginal del LLM es modesto.

---

## 2. Tres moonshots (high-risk, world-first si se entregan)

### M1. Vendedor "ambient computing" — wake word "Dermacells" en iOS

Vendedor maneja entre clientes con manos ocupadas. Wake word activa captura por voz, transcribe con Whisper Turbo on-device (zero-cost), envia al asistente que decide entre tres acciones: (a) crear nota en cliente activo, (b) registrar action futura programada, (c) iniciar nueva venta. Confirmacion por voz, sin tocar pantalla.

- Stack: iOS Foundation Models 2026 (Apple Intelligence) para wake word + intent rough; Whisper Turbo on-device para STT; Sonnet 4.6 server-side para tool routing; SSE -> push notification con resultado.
- Defensibility: 12-18 meses. Requiere dominar permisos iOS background audio, integracion con Foundation Models API que recien estabilizo en marzo 2026, y prompt engineering de baja latencia (<1.2s end-to-end).
- Riesgo: bateria, false positives del wake word, regulacion de grabacion de audio en Argentina.

### M2. Phone call coach en vivo (vendedor llamando a cliente)

Mientras el Vendedor habla por telefono con el cliente, la app graba ambas puntas (con consentimiento), transcribe en streaming, detecta objeciones en tiempo real y sugiere respuestas en una mini-overlay. Al colgar, genera summary + commitments extraidos + acciones futuras programadas.

- Stack: Twilio Voice o WebRTC capture + Whisper Turbo streaming + Sonnet 4.6 con prompt cache pesado (system + customer context cacheados, solo el delta de transcripcion entra fresh) + structured output para "objection_detected" + "suggested_response".
- Defensibility: 18+ meses. Combina compliance (consentimiento grabado), latencia (<800ms para que el coach sea util), y un dataset de objeciones/respuestas curado por industria dermo-cosmetica.
- Riesgo: legalidad de grabacion bilateral en Argentina (Ley 25.520), aceptacion del Vendedor (sensacion de vigilancia).

### M3. Simulador "what-if" sobre snapshot de la operacion

Director pregunta en NL: "Que pasa si subo comision a 14% solo en zona BA durante 3 meses?". El sistema clona el state actual, corre una simulacion deterministica de 90 dias usando comportamiento historico de cada Vendedor + elasticidad aprendida de precio + estacionalidad, y devuelve P&L proyectado, riesgos identificados y comparacion vs baseline.

- Stack: snapshot de Postgres a una DB efimera; simulador Python (Monte Carlo, 1000 runs); Sonnet 4.6 para parsear el escenario y narrar resultados; React + Recharts para visualizar distribuciones.
- Defensibility: 24+ meses. Requiere acumular >12 meses de data limpia, modelar elasticidad por cliente, y un motor de simulacion auditable. Es la unica feature que justifica un Data Scientist dedicado.
- Riesgo: garbage-in garbage-out; el Director puede tomar decisiones sobre simulaciones mal calibradas.

---

## 3. Cinco quick wins (<2 sprints sobre Phase 13 existente)

| QW | Feature | Esfuerzo | Reuso de Phase 13 |
|----|---------|----------|-------------------|
| QW1 | Daily worklist priorizado (cron 6AM, ya hay daily digest) | S (1 sprint) | Reusa `RecordAiUsage`, structured output, prompt cache de system rules |
| QW2 | Conversational filters en lista de clientes ("clientes A en BA con frecuencia decreciente") | M (1.5 sprints) | Reusa function calling con tools `query_customers_by_*`; agregar tool generico `query_customers_advanced` con whitelist de columnas |
| QW3 | Sugerencia de monto/cantidad al crear Sale (basado en historico del cliente) | S (1 sprint) | Reusa context fetch (ultimas 20 transactions); prompt corto con structured output `{suggested_qty, reason}` |
| QW4 | Resumen de hilo de WhatsApp (boton "resumir conversacion" en ficha del cliente) | S (1 sprint) | Reusa LLMClient + leak guard (1 cliente = 1 contexto); zero infra nueva |
| QW5 | Auto-tagger de notas de seguimiento (categoriza note en `objecion / oportunidad / queja / otro`) | S (1 sprint) | Reusa structured output; correr async via queue al guardar nota |

Las 5 son entregables con el modulo AI actual sin nuevas integraciones externas. Costo marginal de tokens: <USD 0.50 por Vendedor/mes para QW1-5 combinados (asumiendo Sonnet 4.6 con prompt cache).

---

## 4. Cambios de arquitectura para soportar workflows agenticos

Phase 13 esta diseñada para query/response sincronico con un LLM. Los agentes multi-step requieren:

### 4.1 State machine de tareas AI

Tabla nueva `ai_tasks`:
```
id, user_id, type (recovery|order_parse|reconciliation|...),
status (pending|running|awaiting_human|completed|failed|cancelled),
context_json, result_json, error_json,
parent_task_id (para sub-tasks), created_at, updated_at
```

Cada agente declara su FSM en codigo (ej: `RecoveryAgent::states = [detecting, drafting, awaiting_approval, sent, followup_scheduled, completed]`). Transiciones registradas en `ai_task_transitions` para auditoria.

### 4.2 Tool catalogue versionado

Tabla `ai_tools` (read-only, deployable via migration):
```
name, version, json_schema, allowed_roles (jsonb),
side_effects (none|read|write), requires_approval (bool),
handler_class
```

Un tool con `side_effects=write` y `requires_approval=true` (ej: `send_whatsapp_message`, `create_sale_draft`) genera una `authorization_request` (reusa el modulo de Phase 11) antes de ejecutar.

### 4.3 Audit trail de acciones AI

Reusa `ai_usage` agregando columnas:
```
task_id (FK ai_tasks),
tools_invoked (jsonb array de {name, args_hash, result_hash}),
human_approver_id (nullable),
reverted_at (nullable, para rollback)
```

Toda accion AI con `side_effects != none` debe ser idempotente y reversible. El sistema mantiene un `reverse_handler` por tool que el Director puede invocar desde el panel para deshacer cambios.

### 4.4 Trigger system (event-driven agents)

Nuevo `AiTriggerListener` suscripto a domain events:
- `CustomerEnteredInactiveState` -> dispara `RecoveryAgent`
- `LotApproachingExpiry` -> dispara `LotDispatchRecommender`
- `NightlyReconciliationCron` -> dispara `XubioReconciliationAgent`

Los triggers crean filas en `ai_tasks` que un worker queue procesa. El Vendedor ve el resultado como notificacion in-app + push.

### 4.5 Sandbox de ejecucion

Para tools con `side_effects=write`, ejecutar dentro de DB transaction con savepoint; si el LeakGuard o validador post-hoc detecta anomalia, rollback automatico. Logs estructurados a tabla `ai_task_executions` con stdout/stderr completos.

### 4.6 Per-tenant rate limiting agentico

`EnforceAiTokenCap` actual mide tokens. Para agentes hay que extender a "ai_actions_per_day" cap (ej: max 200 acciones write/dia/Vendedor) para evitar runaway loops o abuso.

---

## 5. Cost model: USD/Vendedor/mes para AI tier completo

Asumimos: 100 clientes/Vendedor, 30 dias laborables, modelos a precio Anthropic/OpenAI 2026-05.

### Asunciones de pricing
- Claude Sonnet 4.6: USD 3/1M input, USD 15/1M output, USD 0.30/1M cache read.
- Whisper Turbo: USD 0.004/min audio.
- GPT-5 Vision: USD 5/1M input, USD 20/1M output (asumido).
- iOS Foundation Models / Apple Intelligence: gratis on-device.

### Desglose por capacidad

| Capacidad | Volumen mensual estimado | Tokens / costo |
|-----------|--------------------------|----------------|
| Daily worklist (QW1) | 30 runs x 8K input cached + 2K output | 30 x (8K x 0.30 + 2K x 15) = USD 0.97 |
| Inline suggester en Sale (QW3) | 60 ventas x 4K cached + 0.5K output | USD 0.50 |
| Conversational filters (QW2) | 50 queries x 3K input + 1K output | USD 1.20 |
| WA summary (QW4) | 40 hilos x 5K input + 1K output | USD 1.20 |
| Auto-tag notas (QW5) | 200 notas x 0.5K + 0.1K | USD 0.60 |
| Churn prediction | 100 clientes x 1 vez/mes (in-house ML, no LLM) | USD 0.10 (compute) |
| Order parsing agent | 60 pedidos x 6K input + 1.5K output | USD 2.50 |
| Voice notes (Whisper + LLM) | 40 notas x 30s audio + 3K input + 0.5K output | USD 0.30 (Whisper si no es on-device) + USD 0.60 (LLM) |
| Customer recovery agent | 8 customers/mes x 5 steps x 4K + 1K | USD 1.80 |
| Photo of receipt (vision) | 20 fotos x 1500 tokens vision + 1K output | USD 0.55 |
| Phone call coach (moonshot) | 4 llamadas x 10min x streaming | USD 6.00 (Whisper + Sonnet streaming pesado) |
| Anomaly detector + explainer | nightly batch (compartido), prorrateado | USD 0.20 |

Subtotal sin moonshot: **USD 10.32 / Vendedor / mes**.
Con phone call coach (M2): USD 16.32.
Con simulador what-if (M3, prorrateado solo a Directores, ~5 ejecuciones/mes a USD 8 c/u): +USD 40/Director.

### Pricing recomendado al cliente (Dermacells)

- Tier "AI Lite" (QW1-5 + churn): USD 4/Vendedor/mes (margen ~40%).
- Tier "AI Pro" (todo el ranking 1-10 sin moonshots): USD 20/Vendedor/mes (margen ~50%).
- Add-on "Director Insights" (M3 + cross-customer mining): USD 80/Director/mes.

Escala: con 30 Vendedores activos en AI Pro = USD 600/mes ingreso; costo de tokens ~USD 310/mes; margen ~48%. Sostenible si la adopcion supera el 60% de la base.

### Riesgos de costo

- Prompt cache hit ratio menor a 70% rompe el modelo (cache es lo que hace Sonnet barato).
- Vendedores que abusen de queries en NL pueden 10x el consumo; el `EnforceAiTokenCap` debe ser per-user, no global.
- Whisper en cloud (no on-device) duplica el costo de voice notes.

---

## Cierre

Phase 13 deja un cimiento solido (provider-agnostic, leak-guarded, cost-aware) que ya cubre el ~30% de las features del ranking. El gap real para diferenciacion global es el shift de "asistente que responde" a "agente que actua", y eso requiere el trabajo de arquitectura de la seccion 4 antes de poder entregar moonshots.

Recomendacion priorizada para los proximos 3 sprints:
1. Sprint A: QW1 + QW3 + QW4 (entregar valor visible a Vendedor sin nueva infra).
2. Sprint B: Construir state machine + tool catalogue + audit trail (seccion 4.1-4.3).
3. Sprint C: Order parsing agent (#1 del ranking) como primer agente production sobre la nueva infra.

Lo que NO recomiendo en MVP de AI tier: RAG, vector store, fine-tuning propio. La ventana de contexto y prompt caching de Sonnet 4.6 hacen que estas tecnicas sean overkill para 100 clientes/Vendedor.
