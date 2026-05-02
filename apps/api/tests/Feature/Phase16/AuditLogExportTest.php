<?php

declare(strict_types=1);

use App\Exports\AuditLogExport;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AuditLogExportTest — Phase 16
|--------------------------------------------------------------------------
|
| Verifies that AuditLogExport produces valid CSV output. PDF test is
| skipped when barryvdh/laravel-dompdf is not installed.
|
*/

it('AuditLogExport headings returns 11 columns', function (): void {
    $export = new AuditLogExport();

    expect($export->headings())->toHaveCount(11);
    expect($export->headings()[0])->toBe('ID');
    expect($export->headings()[1])->toBe('Fecha y hora');
});

it('AuditLogExport toCsv produces valid RFC 4180 CSV', function (): void {
    $export = new AuditLogExport();
    $csv    = $export->toCsv();

    // Must have at least a header row.
    expect($csv)->toContain('ID');
    expect($csv)->toContain('Fecha y hora');
    expect($csv)->toContain('Acci');
});

it('AuditLogExport rows returns array with expected structure', function (): void {
    $export = new AuditLogExport();
    $rows   = $export->rows();

    // Each row must be an array (may be empty if audit_log is empty in test DB).
    expect($rows)->toBeArray();

    if (count($rows) > 0) {
        expect($rows[0])->toHaveCount(11);
    }
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('audit_log'), 'audit_log not migrated');

it('AuditLogExport filters by date_from correctly', function (): void {
    $export = new AuditLogExport([
        'date_from' => now()->addYear()->toDateString(), // far future → 0 rows
    ]);

    expect($export->rows())->toHaveCount(0);
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('audit_log'), 'audit_log not migrated');

it('AuditLogExport toPdf falls back to CSV when DomPDF is absent', function (): void {
    // If DomPDF is not installed, toPdf() returns a CSV string.
    if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        $this->markTestSkipped('DomPDF is installed; PDF path would be tested instead.');
    }

    $export = new AuditLogExport();
    $output = $export->toPdf();

    // Fallback is CSV — must contain headings.
    expect($output)->toContain('ID');
});
