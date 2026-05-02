<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRM Dermacells — Demo Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: #f9fafb;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 2rem;
            max-width: 540px;
            width: 100%;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        .subtitle {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 1.75rem;
        }
        .group-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #9ca3af;
            margin: 1.25rem 0 0.5rem;
        }
        .persona-list { display: flex; flex-direction: column; gap: 0.5rem; }
        a.persona {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            text-decoration: none;
            color: #111827;
            font-size: 0.875rem;
            transition: background 0.1s, border-color 0.1s;
        }
        a.persona:hover { background: #f3f4f6; border-color: #d1d5db; }
        .badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.1rem 0.5rem;
            border-radius: 9999px;
            white-space: nowrap;
        }
        .badge-director    { background: #fef3c7; color: #92400e; }
        .badge-distributor { background: #dbeafe; color: #1e40af; }
        .badge-seller      { background: #dcfce7; color: #166534; }
        .email { color: #6b7280; font-size: 0.8rem; margin-left: auto; }
        .notice {
            margin-top: 1.75rem;
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #f3f4f6;
            padding-top: 1rem;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>CRM Dermacells — Demo Login</h1>
    <p class="subtitle">Solo en entorno local. Seleccioná una persona para ingresar al panel.</p>

    <div class="group-label">Director</div>
    <div class="persona-list">
        <a href="/dev-login" class="persona">
            <span class="badge badge-director">Director</span>
            Dev Director
            <span class="email">dev@dermacells.local</span>
        </a>
    </div>

    <div class="group-label">Distribuidores</div>
    <div class="persona-list">
        <a href="/dev-login-as/eduardo" class="persona">
            <span class="badge badge-distributor">Distribuidor</span>
            Eduardo — BA
            <span class="email">eduardo@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/andres" class="persona">
            <span class="badge badge-distributor">Distribuidor</span>
            Andres — Cordoba
            <span class="email">andres@demo.dermacells.local</span>
        </a>
    </div>

    <div class="group-label">Vendedoras / Vendedores — BA</div>
    <div class="persona-list">
        <a href="/dev-login-as/maria" class="persona">
            <span class="badge badge-seller">Vendedora</span>
            Maria — BA
            <span class="email">maria@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/juan" class="persona">
            <span class="badge badge-seller">Vendedor</span>
            Juan — BA
            <span class="email">juan@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/sofia" class="persona">
            <span class="badge badge-seller">Vendedora</span>
            Sofia — BA
            <span class="email">sofia@demo.dermacells.local</span>
        </a>
    </div>

    <div class="group-label">Vendedores — Cordoba</div>
    <div class="persona-list">
        <a href="/dev-login-as/carlos" class="persona">
            <span class="badge badge-seller">Vendedor</span>
            Carlos — Cordoba
            <span class="email">carlos@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/ana" class="persona">
            <span class="badge badge-seller">Vendedora</span>
            Ana — Cordoba
            <span class="email">ana@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/diego" class="persona">
            <span class="badge badge-seller">Vendedor</span>
            Diego — Cordoba
            <span class="email">diego@demo.dermacells.local</span>
        </a>
    </div>

    <div class="group-label">Vendedores — Patagonia</div>
    <div class="persona-list">
        <a href="/dev-login-as/lucia" class="persona">
            <span class="badge badge-seller">Vendedora</span>
            Lucia — Patagonia
            <span class="email">lucia@demo.dermacells.local</span>
        </a>
        <a href="/dev-login-as/pablo" class="persona">
            <span class="badge badge-seller">Vendedor</span>
            Pablo — Patagonia
            <span class="email">pablo@demo.dermacells.local</span>
        </a>
    </div>

    <p class="notice">DEMO MODE — local env only. No disponible en produccion.</p>
</div>
</body>
</html>
