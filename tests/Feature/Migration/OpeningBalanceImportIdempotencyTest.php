<?php

namespace Tests\Feature\Migration;

use App\Models\User;
use App\Services\OpeningBalanceImportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Opening Balance Import Hash Idempotency Test (MIG-02, BE-28)
 *
 * Rules verified:
 * 1. Valid opening balance CSV imports successfully and records SHA-256 hash.
 * 2. Duplicate upload of identical file to same branch is strictly rejected (idempotency).
 * 3. Same file uploaded to different branch is permitted (branch isolation).
 * 4. Empty file uploads are rejected.
 */
class OpeningBalanceImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch1;

    private Branch $branch2;

    private User $accountant;

    private OpeningBalanceImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Import Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Import Brand',
            'status' => 'active',
            'sub_status' => 'active',
            'plan' => 'ذهبي',
        ]);

        $this->branch1 = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Branch Alpha',
        ]);

        $this->branch2 = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
            'name' => 'Branch Beta',
        ]);

        $this->accountant = User::factory()->create([
            'name' => 'Accountant Hani',
            'email' => 'hani.import@assab.test',
        ]);

        $this->service = app(OpeningBalanceImportService::class);
    }

    private function sampleCsvContent(): string
    {
        return "account_code,account_name,debit,credit\n"
            ."10101,Cash on Hand,50000.00,0.00\n"
            ."10201,Al Rajhi Bank,120000.00,0.00\n"
            ."20101,Accounts Payable,0.00,170000.00\n";
    }

    /** MIG-02: Initial CSV import processes correctly and records hash. */
    public function test_initial_opening_balance_import_succeeds(): void
    {
        $csv = $this->sampleCsvContent();
        $expectedHash = hash('sha256', trim($csv));

        $import = $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch1->id,
            importedByUserId: $this->accountant->id,
            csvContent: $csv,
            filename: 'alpha_opening_balances.csv'
        );

        $this->assertNotNull($import);
        $this->assertSame($expectedHash, $import->file_hash);
        $this->assertSame(3, $import->rows_count);
        $this->assertSame(17000000, (int) $import->total_debit_halalas); // 170,000.00 SAR
        $this->assertSame(17000000, (int) $import->total_credit_halalas);
        $this->assertSame('completed', $import->status);

        $this->assertDatabaseHas('opening_balance_imports', [
            'id' => $import->id,
            'file_hash' => $expectedHash,
            'branch_id' => $this->branch1->id,
        ]);
    }

    /** MIG-02: Re-uploading identical CSV to same branch is rejected by hash idempotency. */
    public function test_duplicate_file_upload_rejected_by_hash_idempotency(): void
    {
        $csv = $this->sampleCsvContent();

        // First upload succeeds
        $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch1->id,
            importedByUserId: $this->accountant->id,
            csvContent: $csv
        );

        // Second upload of identical content
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Duplicate opening balance file detected. An import with identical hash already exists for this branch.');

        $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch1->id,
            importedByUserId: $this->accountant->id,
            csvContent: $csv
        );
    }

    /** MIG-02: Same file content to different branch is allowed (branch isolation). */
    public function test_identical_file_to_different_branch_is_permitted(): void
    {
        $csv = $this->sampleCsvContent();

        // Branch 1
        $import1 = $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch1->id,
            importedByUserId: $this->accountant->id,
            csvContent: $csv
        );

        // Branch 2
        $import2 = $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch2->id,
            importedByUserId: $this->accountant->id,
            csvContent: $csv
        );

        $this->assertNotNull($import1);
        $this->assertNotNull($import2);
        $this->assertSame($import1->file_hash, $import2->file_hash);
        $this->assertNotSame($import1->branch_id, $import2->branch_id);
        $this->assertDatabaseCount('opening_balance_imports', 2);
    }

    /** MIG-02: Empty CSV content is rejected. */
    public function test_empty_csv_content_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Opening balance file content cannot be empty.');

        $this->service->importOpeningBalance(
            companyId: $this->company->id,
            branchId: $this->branch1->id,
            importedByUserId: $this->accountant->id,
            csvContent: "   \n  \r\n  "
        );
    }
}
