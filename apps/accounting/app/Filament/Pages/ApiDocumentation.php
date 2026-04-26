<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ApiDocumentation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $activeNavigationIcon = 'heroicon-s-book-open';

    protected static ?string $navigationGroup = 'API';

    protected static ?string $navigationLabel = 'Dokumentasi';

    protected static ?string $title = 'Dokumentasi API';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.api-documentation';

    protected static ?string $slug = 'api/dokumentasi';

    public function getHeading(): string
    {
        return 'Dokumentasi API';
    }

    public function getSubheading(): ?string
    {
        return 'Daftar endpoint, autentikasi, dan contoh penggunaan untuk integrasi pihak ketiga.';
    }

    /** @return array<int, array<string, mixed>> */
    public function getEndpoints(): array
    {
        return [
            [
                'method' => 'POST',
                'path'   => '/api/v1/journals',
                'name'   => 'Buat & Posting Jurnal',
                'desc'   => 'Buat jurnal baru dengan minimal 2 baris (debit/kredit), langsung diposting. Mendukung idempotency_key untuk anti-duplikasi.',
                'auth'   => 'Bearer token (header `Authorization: Bearer <token>`)',
                'perms'  => 'journal.create + journal.post',
                'rate'   => '60 req/menit per token',
                'request' => [
                    'entity_id' => '01HXXXXXXXXXXXXXXXXXXXXXXX',
                    'reference' => 'INV-2026-0042',
                    'date'      => '2026-04-25',
                    'currency'  => 'IDR',
                    'lines'     => [
                        ['account_code' => '1-1010', 'debit' => 5000000, 'credit' => 0, 'memo' => 'Kas masuk'],
                        ['account_code' => '4-1000', 'debit' => 0, 'credit' => 5000000, 'memo' => 'Pendapatan jasa'],
                    ],
                    'metadata'        => [
                        'source_app' => 'payroll',
                        'source_id'  => 'PAYRUN-202604',
                        'memo'       => 'Auto-post dari payroll April',
                    ],
                    'idempotency_key' => 'payroll:202604:posting',
                ],
                'response' => [
                    'success' => [
                        'status' => 201,
                        'body' => [
                            'journal_id' => '01HXXXXXXXXXXXXXXXXXXXXXXX',
                            'status'     => 'posted',
                            'audit_id'   => '01HXXXXXXXXXXXXXXXXXXXXXXX',
                        ],
                    ],
                    'errors' => [
                        ['code' => 401, 'error' => 'unauthorized',              'when' => 'Token tidak valid / kedaluwarsa.'],
                        ['code' => 403, 'error' => 'token_missing_app_scope',   'when' => 'Token belum di-scope ke app.'],
                        ['code' => 403, 'error' => 'source_app_mismatch',       'when' => '`metadata.source_app` ≠ scope token.'],
                        ['code' => 403, 'error' => 'forbidden',                 'when' => 'Permission tidak cukup.'],
                        ['code' => 409, 'error' => 'duplicate_idempotency_key', 'when' => 'idempotency_key sudah pernah dipakai.'],
                        ['code' => 422, 'error' => 'entity_not_found',          'when' => 'entity_id tidak ada.'],
                        ['code' => 422, 'error' => 'no_open_period_for_date',   'when' => 'Tidak ada periode open untuk tanggal.'],
                        ['code' => 422, 'error' => 'account_code_not_found',    'when' => 'account_code tidak terdaftar di entity.'],
                        ['code' => 422, 'error' => 'journal_invalid',           'when' => 'Validasi double-entry gagal (selisih D/K).'],
                    ],
                ],
            ],
        ];
    }
}
