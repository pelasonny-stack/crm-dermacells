<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AuditLogExport — streams filtered audit_log rows for Excel/CSV export.
 *
 * Uses DB facade (not Eloquent) because audit_log is partitioned and
 * declared append-only; we never hydrate it as a full model graph.
 *
 * This class is intentionally decoupled from Maatwebsite\Excel so it can
 * be used as a plain data source by any driver. The Filament page
 * (AuditLogViewer) wires it to a response download.
 *
 * If pxlrbt/filament-excel or maatwebsite/excel are installed in the future,
 * implement the FromQuery + WithHeadings contracts and add the traits. For now
 * we expose a manual array/generator approach that works without the package.
 *
 * PDF export via barryvdh/laravel-dompdf: add to composer if needed.
 * Presence check: class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)
 */
final class AuditLogExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters = [],
    ) {}

    /**
     * Returns the headings row for the spreadsheet.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID',
            'Fecha y hora',
            'Usuario',
            'Email',
            'Acción',
            'Tabla',
            'ID registro',
            'Campo',
            'Valor anterior',
            'Valor nuevo',
            'Hash HMAC',
        ];
    }

    /**
     * Returns rows as a flat array for streaming.
     * Each element is a numerically-indexed array matching headings().
     *
     * @return array<int, array<int, string|null>>
     */
    public function rows(): array
    {
        return $this->query()
            ->get()
            ->map(fn (object $row): array => [
                $row->id,
                $row->occurred_at,
                $row->user_name ?? 'Sistema',
                $row->user_email ?? '',
                $row->action,
                $row->table_name,
                $row->record_id,
                $row->field_name ?? '',
                $row->old_value,
                $row->new_value,
                $row->hmac_hash ?? '',
            ])
            ->all();
    }

    /**
     * Renders a Blade-based PDF and returns the raw PDF bytes.
     * Falls back to a plain text representation if DomPDF is unavailable.
     */
    public function toPdf(): string
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            // Fallback: plain-text CSV when DomPDF is absent.
            return $this->toCsv();
        }

        $rows  = $this->rows();
        $heads = $this->headings();

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.audit-log-pdf', [
            'headings' => $heads,
            'rows'     => $rows,
        ])->setPaper('a4', 'landscape');

        return $pdf->output();
    }

    /**
     * Returns RFC 4180 CSV string of filtered rows.
     */
    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $this->headings());

        foreach ($this->rows() as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function query(): Builder
    {
        $query = DB::table('audit_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->select([
                'al.id',
                'al.occurred_at',
                DB::raw("COALESCE(u.full_name, al.user_id::TEXT) AS user_name"),
                'u.email AS user_email',
                'al.action',
                'al.table_name',
                'al.record_id',
                'al.field_name',
                'al.old_value',
                'al.new_value',
                'al.hmac_hash',
            ])
            ->orderBy('al.occurred_at', 'desc')
            ->limit(50_000); // Safety cap — large exports should go async

        if (! empty($this->filters['date_from'])) {
            $query->where('al.occurred_at', '>=', $this->filters['date_from']);
        }

        if (! empty($this->filters['date_to'])) {
            $query->where('al.occurred_at', '<=', $this->filters['date_to'] . ' 23:59:59');
        }

        if (! empty($this->filters['user_id'])) {
            $query->where('al.user_id', $this->filters['user_id']);
        }

        if (! empty($this->filters['table_name'])) {
            $query->where('al.table_name', $this->filters['table_name']);
        }

        if (! empty($this->filters['action'])) {
            $query->where('al.action', $this->filters['action']);
        }

        return $query;
    }
}
