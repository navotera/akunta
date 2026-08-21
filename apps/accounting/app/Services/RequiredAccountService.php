<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RequiredAccountService
{
    public const PREPAID_TAX = 'tax.prepaid';

    public const CURRENT_TAX_PAYABLE_PROVISION = 'tax.current_payable_provision';

    public const CURRENT_TAX_PAYABLE_DEFINITIVE = 'tax.current_payable_definitive';

    public const CURRENT_TAX_EXPENSE = 'tax.current_expense';

    /**
     * @return list<array{
     *   system_key: string,
     *   code: string,
     *   name: string,
     *   aliases: list<string>,
     *   type: string,
     *   normal_balance: string,
     *   availability: string,
     *   parent_codes: list<string>,
     *   description: string,
     *   legal_basis: ?string
     * }>
     */
    public function definitions(): array
    {
        return [
            [
                'system_key' => self::PREPAID_TAX,
                'code' => '1498',
                'name' => 'Pajak Dibayar di Muka',
                'aliases' => ['Pajak Dibayar di Muka'],
                'type' => 'asset',
                'normal_balance' => 'debit',
                'availability' => Account::AVAILABILITY_BOTH,
                'parent_codes' => ['1400', '1100', '1000'],
                'description' => 'Pembayaran atau pemotongan pajak yang dapat diperhitungkan sebagai kredit pajak. Digunakan berdasarkan bukti bayar atau bukti potong yang valid.',
                'legal_basis' => 'UU Pajak Penghasilan dan ketentuan kredit pajak yang berlaku.',
            ],
            [
                'system_key' => self::CURRENT_TAX_PAYABLE_PROVISION,
                'code' => '2197',
                'name' => 'Utang PPh Badan - Provisi',
                'aliases' => ['Utang PPh Badan - Provisi', 'Utang PPh Badan Provisi', 'Utang PPh Badan'],
                'type' => 'liability',
                'normal_balance' => 'credit',
                'availability' => Account::AVAILABILITY_INTERN,
                'parent_codes' => ['2100', '2000'],
                'description' => 'Estimasi kewajiban PPh badan periode berjalan sebelum menjadi kewajiban definitif. Digunakan sebagai pasangan Beban Pajak Penghasilan Kini pada jurnal provisi Intern.',
                'legal_basis' => null,
            ],
            [
                'system_key' => self::CURRENT_TAX_PAYABLE_DEFINITIVE,
                'code' => '2198',
                'name' => 'Utang PPh Badan Definitif',
                'aliases' => ['Utang PPh Badan Definitif', 'Utang PPh Badan Terutang'],
                'type' => 'liability',
                'normal_balance' => 'credit',
                'availability' => Account::AVAILABILITY_BOTH,
                'parent_codes' => ['2100', '2000'],
                'description' => 'Kewajiban PPh badan yang telah dihitung secara definitif berdasarkan rekonsiliasi dan dokumen perpajakan. Digunakan sampai kewajiban dibayar atau diselesaikan.',
                'legal_basis' => 'UU Pajak Penghasilan dan ketentuan penghitungan PPh Badan yang berlaku.',
            ],
            [
                'system_key' => self::CURRENT_TAX_EXPENSE,
                'code' => '6998',
                'name' => 'Beban Pajak Penghasilan Kini',
                'aliases' => ['Beban Pajak Penghasilan Kini'],
                'type' => 'expense',
                'normal_balance' => 'debit',
                'availability' => Account::AVAILABILITY_INTERN,
                'parent_codes' => ['6000'],
                'description' => 'Beban pajak penghasilan kini berdasarkan penghasilan kena pajak setelah rekonsiliasi fiskal. Digunakan pada jurnal provisi pajak buku Intern.',
                'legal_basis' => null,
            ],
        ];
    }

    /** @return Collection<string, Account> */
    public function ensure(Entity $entity): Collection
    {
        return DB::transaction(function () use ($entity): Collection {
            $accounts = Account::query()->where('entity_id', $entity->id)->get();
            $result = collect();
            $independentBooks = data_get($entity->workspace_settings, 'bookkeeping_mode') === 'independent_books';

            foreach ($this->definitions() as $definition) {
                $account = $accounts->firstWhere('system_key', $definition['system_key'])
                    ?? $this->findCompatibleLegacyAccount($accounts, $definition);

                if (! $account) {
                    $account = new Account([
                        'entity_id' => $entity->id,
                        'code' => $this->availableCode($accounts, $definition['code']),
                    ]);
                }
                $description = filled($account->description)
                    ? $account->description
                    : $definition['description'];
                $legalBasis = filled($account->legal_basis)
                    ? $account->legal_basis
                    : $definition['legal_basis'];

                $availability = $independentBooks
                    ? $definition['availability']
                    : Account::AVAILABILITY_INTERN;
                $parent = $accounts->first(fn (Account $candidate): bool => in_array($candidate->code, $definition['parent_codes'], true)
                    && $candidate->type === $definition['type']
                    && ! $candidate->is_postable);
                $code = $account->code;
                if ($entity->is_fake_data
                    && strcasecmp($code, $definition['code']) !== 0
                    && ! $accounts->contains(fn (Account $candidate): bool => $candidate->id !== $account->id
                        && strcasecmp($candidate->code, $definition['code']) === 0)) {
                    $code = $definition['code'];
                }

                $account->forceFill([
                    'entity_id' => $entity->id,
                    'system_key' => $definition['system_key'],
                    'code' => $code,
                    'name' => $definition['name'],
                    'description' => $description,
                    'type' => $definition['type'],
                    'normal_balance' => $definition['normal_balance'],
                    'parent_account_id' => $parent?->id,
                    'is_postable' => true,
                    'is_active' => true,
                    'availability' => $availability,
                    'legal_basis' => $legalBasis,
                ])->save();

                if (! $accounts->contains('id', $account->id)) {
                    $accounts->push($account);
                }
                $result->put($definition['system_key'], $account);
            }

            return $result;
        });
    }

    /** @param array{aliases: list<string>, type: string, normal_balance: string, availability: string} $definition */
    private function findCompatibleLegacyAccount(Collection $accounts, array $definition): ?Account
    {
        return $accounts->first(function (Account $account) use ($definition): bool {
            if ($account->system_key !== null
                || $account->type !== $definition['type']
                || $account->normal_balance !== $definition['normal_balance']) {
                return false;
            }

            if ($definition['system_key'] === self::CURRENT_TAX_PAYABLE_PROVISION
                && $account->availability !== Account::AVAILABILITY_INTERN) {
                return false;
            }

            return collect($definition['aliases'])->contains(
                fn (string $alias): bool => strcasecmp(trim($account->name), $alias) === 0,
            );
        });
    }

    private function availableCode(Collection $accounts, string $preferredCode): string
    {
        if (! $accounts->contains(fn (Account $account): bool => strcasecmp($account->code, $preferredCode) === 0)) {
            return $preferredCode;
        }

        for ($suffix = 1; $suffix <= 99; $suffix++) {
            $candidate = $preferredCode.'.'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            if (! $accounts->contains(fn (Account $account): bool => strcasecmp($account->code, $candidate) === 0)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Kode akun wajib {$preferredCode} dan seluruh kode alternatifnya sudah digunakan.");
    }
}
