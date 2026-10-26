<?php

namespace Tests\Feature\Integration;

use App\Services\FinancialIntegrationService;
use DomainException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Financial Integration Schema Validation Test (INTG-01, BE-29)
 *
 * Rules verified:
 * 1. Financial general ledger payloads require strict double-entry balancing.
 * 2. Payroll liability schemas strictly prohibit automatic payroll deductions (ASSET-02 alignment).
 * 3. Exact Halala integer precision and ISO currency 'SAR' are enforced.
 * 4. Standard export format produces compliant envelope with schema version.
 */
class FinancialIntegrationSchemaValidationTest extends TestCase
{
    private FinancialIntegrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FinancialIntegrationService::class);
    }

    /** INTG-01: Balanced general ledger payload passes validation. */
    public function test_balanced_general_ledger_payload_passes_validation(): void
    {
        $payload = [
            'batch_id' => 'BATCH-2026-10-A',
            'company_id' => Str::uuid()->toString(),
            'branch_id' => Str::uuid()->toString(),
            'currency' => 'SAR',
            'entries' => [
                [
                    'account_code' => '10101',
                    'account_name' => 'Main Cash Safe',
                    'debit_halalas' => 500000, // 5,000.00 SAR
                    'credit_halalas' => 0,
                    'narration' => 'Daily closing cash transfer',
                ],
                [
                    'account_code' => '40101',
                    'account_name' => 'Sales Revenue',
                    'debit_halalas' => 0,
                    'credit_halalas' => 500000, // 5,000.00 SAR
                    'narration' => 'Daily closing sales revenue',
                ],
            ],
        ];

        $validated = $this->service->validateFinancialPayload(
            $payload,
            FinancialIntegrationService::SCHEMA_GENERAL_LEDGER
        );

        $this->assertSame('BATCH-2026-10-A', $validated['batch_id']);
        $this->assertCount(2, $validated['entries']);
    }

    /** INTG-01: Unbalanced double-entry ledger is rejected. */
    public function test_unbalanced_general_ledger_payload_is_rejected(): void
    {
        $payload = [
            'batch_id' => 'BATCH-ERR-01',
            'company_id' => Str::uuid()->toString(),
            'branch_id' => Str::uuid()->toString(),
            'currency' => 'SAR',
            'entries' => [
                [
                    'account_code' => '10101',
                    'account_name' => 'Cash',
                    'debit_halalas' => 500000, // 5,000 SAR
                    'credit_halalas' => 0,
                ],
                [
                    'account_code' => '40101',
                    'account_name' => 'Revenue',
                    'debit_halalas' => 0,
                    'credit_halalas' => 450000, // 4,500 SAR (Out of balance by 500 SAR)
                ],
            ],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Double-entry balance violation: Total debits (500000 halalas) must equal total credits (450000 halalas).');

        $this->service->validateFinancialPayload(
            $payload,
            FinancialIntegrationService::SCHEMA_GENERAL_LEDGER
        );
    }

    /** INTG-01 / ASSET-02: Payroll liability schema rejects automatic payroll deduction. */
    public function test_payroll_liability_schema_rejects_automatic_payroll_deduction(): void
    {
        $payload = [
            'company_id' => Str::uuid()->toString(),
            'branch_id' => Str::uuid()->toString(),
            'employee_id' => Str::uuid()->toString(),
            'liability_type' => 'asset_loss',
            'amount_halalas' => 250000,
            'automatic_payroll_deduction' => true, // Attempting illegal salary deduction
        ];

        $this->expectException(ValidationException::class);

        $this->service->validateFinancialPayload(
            $payload,
            FinancialIntegrationService::SCHEMA_PAYROLL_LIABILITY
        );
    }

    /** INTG-01: Export produces standard schema envelope. */
    public function test_export_financial_data_formats_standard_envelope(): void
    {
        $companyId = Str::uuid()->toString();
        $records = [['id' => '1', 'name' => 'Sample Record']];

        $export = $this->service->exportFinancialData(
            $companyId,
            FinancialIntegrationService::SCHEMA_GENERAL_LEDGER,
            $records
        );

        $this->assertSame('1.0', $export['schema_version']);
        $this->assertSame('SAR', $export['currency']);
        $this->assertSame(FinancialIntegrationService::SCHEMA_GENERAL_LEDGER, $export['schema_type']);
        $this->assertSame($companyId, $export['company_id']);
        $this->assertNotEmpty($export['generated_at']);
    }
}
