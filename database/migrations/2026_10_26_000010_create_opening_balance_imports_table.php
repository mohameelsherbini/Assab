<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opening_balance_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->string('file_hash', 64)->index();
            $table->string('filename')->default('opening_balances.csv');
            $table->uuid('imported_by_user_id');
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedBigInteger('total_debit_halalas')->default(0);
            $table->unsignedBigInteger('total_credit_halalas')->default(0);
            $table->string('status', 32)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'file_hash'], 'uniq_opening_bal_hash_per_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balance_imports');
    }
};
