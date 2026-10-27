<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * TEST-ONLY schema stand-in for the BE-09 settlement requests table.
 *
 * The production migration `2026_10_26_000001_create_asab_employee_settlement_requests_table`
 * is NOT part of this experimental branch: it is unapproved (Mahmoud's schema
 * gate). These tests cannot exercise the settlement logic without the table,
 * so the columns are created here exactly as the historical migration defines
 * them. This changes no production schema and must be replaced by the approved
 * migration at integration time.
 */
trait CreatesSettlementRequestsTable
{
    protected function createSettlementRequestsTable(): void
    {
        if (Schema::hasTable('asab_employee_settlement_requests')) {
            return;
        }

        Schema::create('asab_employee_settlement_requests', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('employee_id')->index();
            $table->unsignedBigInteger('amount_halalas');
            $table->text('reason');
            $table->string('status', 32)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by_user_id')->index();
            $table->uuid('reviewed_by_user_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('movement_id')->nullable()->index();
            $table->string('idempotency_key', 64)->nullable()->index();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'asab_emp_settle_emp_status_idx');
        });
    }
}
