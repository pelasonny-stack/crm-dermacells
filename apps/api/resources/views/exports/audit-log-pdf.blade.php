<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Log de Auditoria — Dermacells</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 8px;
            color: #1a1a1a;
            margin: 0;
            padding: 15px;
        }
        h1 {
            font-size: 13px;
            margin-bottom: 4px;
        }
        .meta {
            font-size: 7px;
            color: #666;
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #1f2937;
            color: #fff;
            padding: 4px 3px;
            text-align: left;
            font-size: 7px;
        }
        td {
            padding: 3px;
            border-bottom: 1px solid #e5e7eb;
            word-break: break-all;
            max-width: 120px;
        }
        tr:nth-child(even) td {
            background: #f9fafb;
        }
        .badge-created  { color: #16a34a; font-weight: bold; }
        .badge-updated  { color: #d97706; font-weight: bold; }
        .badge-deleted  { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Log de Auditoria — CRM Dermacells</h1>
    <div class="meta">
        Generado: {{ now()->format('d/m/Y H:i:s') }} ART —
        Total registros: {{ count($rows) }} —
        Inmutable: no editable ni eliminable por ningun usuario (§16.13)
    </div>

    <table>
        <thead>
            <tr>
                @foreach($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $index => $cell)
                        <td @if($index === 4)
                            class="badge-{{ strtolower((string)$cell) }}"
                        @endif>
                            {{ $cell ?? '—' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
