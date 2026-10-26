<?php

namespace App\Services;

use App\Models\OpeningBalanceImport;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningBalanceImportService
{
    /**
     * Import branch opening balance with strict hash-based idempotency (MIG-02, BE-28).
     *
     * In accordance with ASSAB Business Rules v3.5, the system enforces SHA-256
     * hash-based idempotency per branch, rejecting duplicate uploads.
     */
    public function importOpeningBalance(
        string $companyId,
        string $branchId,
        string $importedByUserId,
        string $csvContent,
        string $filename = 'opening_balances.csv'
    ): OpeningBalanceImport {
        $normalizedContent = trim($csvContent);
        if ($normalizedContent === '') {
            throw new DomainException('Opening balance file content cannot be empty.');
        }

        $fileHash = hash('sha256', $normalizedContent);

        if ($this->isHashAlreadyImported($companyId, $branchId, $fileHash)) {
            throw new DomainException('Duplicate opening balance file detected. An import with identical hash already exists for this branch.');
        }

        $lines = preg_split("/\r\n|\n|\r/", $normalizedContent);
        $header = str_getcsv(array_shift($lines) ?? '');

        $rowsCount = 0;
        $totalDebitHalalas = 0;
        $totalCreditHalalas = 0;
        $parsedEntries = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $row = str_getcsv($trimmed);
            $rowsCount++;

            // Support standard accounting opening balance CSV:
            // [account_code, account_name, debit, credit]
            $debit = isset($row[2]) ? (float) $row[2] : 0.0;
            $credit = isset($row[3]) ? (float) $row[3] : 0.0;

            $debitHalalas = (int) round($debit * 100);
            $creditHalalas = (int) round($credit * 100);

            $totalDebitHalalas += $debitHalalas;
            $totalCreditHalalas += $creditHalalas;

            $parsedEntries[] = [
                'code' => $row[0] ?? '',
                'name' => $row[1] ?? '',
                'debit_halalas' => $debitHalalas,
                'credit_halalas' => $creditHalalas,
            ];
        }

        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $fileHash,
            $filename,
            $importedByUserId,
            $rowsCount,
            $totalDebitHalalas,
            $totalCreditHalalas,
            $parsedEntries
        ) {
            return OpeningBalanceImport::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'file_hash' => $fileHash,
                'filename' => $filename,
                'imported_by_user_id' => $importedByUserId,
                'rows_count' => $rowsCount,
                'total_debit_halalas' => $totalDebitHalalas,
                'total_credit_halalas' => $totalCreditHalalas,
                'status' => 'completed',
                'metadata' => [
                    'entries_sample' => array_slice($parsedEntries, 0, 5),
                ],
            ]);
        });
    }

    /**
     * Check if a file with identical hash has already been imported for this branch.
     */
    public function isHashAlreadyImported(string $companyId, string $branchId, string $fileHash): bool
    {
        return OpeningBalanceImport::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('file_hash', $fileHash)
            ->exists();
    }
}
