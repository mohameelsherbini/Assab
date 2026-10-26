<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_cashier_expense_delegations')) {
            Schema::create('branch_cashier_expense_delegations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('branch_id')->index();
                $table->uuid('cashier_user_id')->index();
                $table->uuid('granted_by_user_id')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('revoked_at')->nullable();
                $table->uuid('revoked_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'cashier_user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_cashier_expense_delegations');
    }
};
