# UX Opportunities — CRM Dermacells AGF Mesenchymal

**Research basis:** Dermacells CRM v4.0 spec + applied UX literature + B2B field sales behavior patterns. Confidence: HIGH on persona constraints + anti-patterns; MEDIUM on quantitative adoption predictions (no user interviews yet).

## 1. Three personas, day-in-life

### Gonzalo — Vendedor en campo, Conurbano Norte
- 31 años, motorbike, Android Galaxy A series, 4G dead zones
- Currently uses: WhatsApp + paper notebook + Google Maps + Excel screenshot
- 8 visits/day, 30km, helmet-on, hands-occupied, sun glare
- Rejection triggers: load >3s, >2 taps to action, lost data on call interrupt, mandatory irrelevant fields
- Daily motivation: ONE screen he opens unprompted = commissions
- Logs visits in batches at lunch (data already stale)
- Cobro flow: needs immediate "este efectivo va a Mirta" surfaced

### Mirta — Distribuidora Norte, 5 vendedores 120 clientes
- 47 años, desktop + iPhone 12 + part-time assistant
- Maintains parallel Excel of all reality (CRM not trusted)
- End-of-month reconciliation = 6-8 hrs manual stress
- Wants: live cash position, auto-rendición preview, predictive month-end, early-warning underperformers

### Federico — Director multi-zona
- 52 años, iPad Pro + MacBook, impatient with slow data
- 4 WhatsApp messages from distribuidores by 9 AM = context switches
- Authorizations no batch view, no priority sort
- Wants: AI weekly digest as voice note (not dashboard), natural-language Q&A, batch authorization tray sorted by financial impact

## 2. Top 10 UX improvements (impact/effort)

| # | Improvement | Impact | Effort | Ratio |
|---|---|---|---|---|
| 1 | Commission progress ring as home anchor | 5 | 1 | **5.0** |
| 2 | Director batch authorization tray | 4 | 1 | **4.0** |
| 3 | One-tap visit log + voice transcription | 5 | 2 | **2.5** |
| 4 | Swipe-to-action on priority client list | 4 | 2 | **2.0** |
| 5 | Distribuidor month-end simulation | 4 | 2 | **2.0** |
| 6 | Birthday push w/ pre-filled WhatsApp deep link | 2 | 1 | **2.0** |
| 7 | Offline-first PWA + sync indicator | 5 | 3 | **1.67** |
| 8 | QR-based clinic check-in | 3 | 2 | **1.5** |
| 9 | WhatsApp-initiated cobro registration | 4 | 3 | **1.33** |
| 10 | AI weekly digest as push (not dashboard) | 4 | 3 | **1.33** |

## 3. Five disruptive UX patterns no CRM has

### Pattern 1 — Stories-format daily briefing (Instagram/Snapchat)
3-5 vertical full-screen cards on app open. Each = one piece info + max 2 actions. Auto-advance 4s. Reduces morning briefing 8min → 90s. No B2B CRM ships this.

### Pattern 2 — Photo-to-order via AI vision
Vendedor photographs stock shelf or written order note. Vision model (GPT-4o/Claude) extracts items + creates Sale draft. WhatsApp customer can also send photo direct. Zero CRM in pharma LATAM has this.

### Pattern 3 — Commissions as game layer (Apple Fitness rings)
3 nested rings: cajas-vs-meta, USD-cobrado-vs-tier, clientes-activos-vs-prev-month. Color transitions green/amber/red. Personal coach framing, NOT surveillance.

### Pattern 4 — Voice command palette + wake word
"Hey Dermacells, María Cruz pidió 3 cajas Dermal pago 30 días" → bot confirma estructurado → vendedor dice "confirmar". Web Speech API + intent classification + fuzzy client lookup. Critical: helmeted/cold-hand scenarios.

### Pattern 5 — WhatsApp-as-primary-interface (Telegram bot UX)
Vendedor never opens app for cobros. Texta "Cobré María Cruz 50000 transferencia" al bot Dermacells. Bot responde Quick Reply card "Confirmar?" → vendedor "sí". WhatsApp Business API ya en scope. Más radical de todos.

## 4. Three quick wins (<1 sprint cada uno)

### QW1 — Commission progress ring (3-4 días)
Reemplazar top vendedor dashboard con ring grande USD-cobrado/next-tier. Cero new data. Pure UI. Highest intrinsic motivation/effort ratio.

### QW2 — Swipe-right "contact logged" (4-5 días)
Gesture en daily priority list. Right = log contacto (1 confirm tap), Left = scheduled action. Reduce 4 taps + 2 nav → 1 swipe + 1 tap.

### QW3 — Director batch auth tray (3-4 días)
Single screen all open authorizations sorted USD desc. Inline approve/reject + "Aprobar seleccionados" bulk. Director response time 40min → 5min/morning batch.

## 5. Anti-patterns industria a evitar

### AP1 — Forms everywhere, fields first
Salesforce 22 default fields, HubSpot 16. Field workers abandon. Dermacells: progressive disclosure, capture mínimo viable en momento de acción. NUNCA agregar dropdowns "motivo visita" / "resultado comercial" en log time.

### AP2 — Dashboard graveyards
NN/g 2022: 68% widgets nunca interactuados después semana 1. Cada widget debe tener primary action — si tap no lleva a algo accionable, el widget no va.

### AP3 — Notification fatigue
15+ alert types como push = vendedor desactiva en 2 semanas. Tiering obligatorio:
- T1 interrupt-worthy: cobro vencido, stock bajo cero, scheduled vencido
- T2 morning briefing only: frecuencia decreciente, primer compra sin recompra
- T3 weekly digest: zona en riesgo trends, evolución general

### AP4 — 12-tab navigation flat
Vendedor mobile bottom nav max 3 items: Hoy / Clientes / Yo. Stock central invisible para Vendedor. Resto via context (client card surfaces ventas+cobros) o command palette.

### AP5 — Connectivity-required for every action
NetSuite/SAP/Zoho asumen always-on. LTE drop en clínicas/elevadores/ascensores → users learn "no funciona" → vuelven al notebook. Offline-first arquitectura desde día 1, NO post-launch fix. Solo NETWORK-required: Xubio invoice + BNA fetch. Resto offline.

## Critical gaps before sprint 1

**Investigación primaria mínima**:
1. Vendedor interviews (n=5): morning routine, tool sequence, visits/day, longest uninterrupted device window
2. Distribuidor shadowing (n=2): full month-end cycle observation
3. Device + connectivity audit: Android/iOS models, OS versions, PWA Service Worker support
4. WhatsApp usage baseline: ¿ya envían pagos por texto? Si sí → bot UX cero learning curve
5. Alert tolerance: cuántos pushes/día antes de desactivar nueva source

Highest-leverage investment pre-sprint-1: **5 contextual interviews vendedores entre visitas** (no en oficina), en sus dispositivos reales.
