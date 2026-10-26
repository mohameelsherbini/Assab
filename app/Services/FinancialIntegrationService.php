<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinancialIntegrationService
{
    public const SCHEMA_GENERAL_LEDGER = 'general_ledger';

    public const SCHEMA_OPENING_BALANCE = 'opening_balance';

    public const SCHEMA_PAYROLL_LIABILITY = 'payroll_liability';

    public const SCHEMA_INVENTORY_VALUATION = 'inventory_valuation';

    /**
     * Validate financial import/export data against approved standard schemas (INTG-01, BE-29).
     */
    public function validateFinancialPayload(array $payload, string $schemaType): array
    {
        $rules = match ($schemaType) {
            self::SCHEMA_GENERAL_LEDGER => [
                'batch_id' => 'required|string',
                'company_id' => 'required|uuid',
                'branch_id' => 'required|uuid',
                'currency' => 'required|string|in:SAR',
                'entries' => 'required|array|min:2',
                'entries.*.account_code' => 'required|string|max:32',
                'entries.*.account_name' => 'required|string|max:128',
                'entries.*.debit_halalas' => 'required|integer|min:0',
                'entries.*.credit_halalas' => 'required|integer|min:0',
                'entries.*.narration' => 'nullable|string|max:255',
            ],
            self::SCHEMA_OPENING_BALANCE => [
                'company_id' => 'required|uuid',
                'branch_id' => 'required|uuid',
                'fiscal_year' => 'required|integer|min:2020|max:2099',
                'entries' => 'required|array|min:1',
                'entries.*.account_code' => 'required|string|max:32',
                'entries.*.account_name' => 'required|string|max:128',
                'entries.*.debit_halalas' => 'required|integer|min:0',
                'entries.*.credit_halalas' => 'required|integer|min:0',
            ],
            self::SCHEMA_PAYROLL_LIABILITY => [
                'company_id' => 'required|uuid',
                'branch_id' => 'required|uuid',
                'employee_id' => 'required|uuid',
                'liability_type' => 'required|string|in:asset_loss,shortage,advance',
                'amount_halalas' => 'required|integer|min:1',
                'automatic_payroll_deduction' => 'required|boolean|declined', // strictly false per ASSET-02
            ],
            self::SCHEMA_INVENTORY_VALUATION => [
                'company_id' => 'required|uuid',
                'branch_id' => 'required|uuid',
                'year_month' => 'required|regex:/^\d{4}-\d{2}$/',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|uuid',
                'items.*.unit_cost_halalas' => 'required|integer|min:0',
                'items.*.quantity' => 'required|numeric|min:0',
            ],
            default => throw new DomainException("Unsupported financial schema type: {$schemaType}"),
        };

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        // Enforce double-entry debit-credit balancing for ledger and opening balance
        if (in_array($schemaType, [self::SCHEMA_GENERAL_LEDGER, self::SCHEMA_OPENING_BALANCE], true)) {
            $totalDebit = 0;
            $totalCredit = 0;
            foreach ($validated['entries'] as $entry) {
                $totalDebit += (int) $entry['debit_halalas'];
                $totalCredit += (int) $entry['credit_halalas'];
            }

            if ($totalDebit !== $totalCredit) {
                throw new DomainException("Double-entry balance violation: Total debits ({$totalDebit} halalas) must equal total credits ({$totalCredit} halalas).");
            }
        }

        return $validated;
    }

    /**
     * Export financial records formatted to standard schema contract (INTG-01).
     */
    public function exportFinancialData(string $companyId, string $schemaType, array $records): array
    {
        return [
            'schema_version' => '1.0',
            'schema_type' => $schemaType,
            'company_id' => $companyId,
            'generated_at' => now()->toIso8601String(),
            'currency' => 'SAR',
            'data' => $records,
        ];
    }
}
