<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BE-09 / STMT-02: Governed Employee Settlement Requests.
 *
 * Mandatory reason, pending state, and Head Accountant approval workflow before balance credit posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_employee_settlement_requests')) {
            return;
        }

        Schema::create('asab_employee_settlement_requests', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('asab_employee_settlement_requests');
    }
};
