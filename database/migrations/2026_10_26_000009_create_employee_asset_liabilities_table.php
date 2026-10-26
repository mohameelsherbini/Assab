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
        Schema::create('employee_asset_liabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('employee_id')->index();
            $table->uuid('asset_id')->index();
            $table->unsignedBigInteger('registered_value_halalas');
            $table->string('liability_status', 32)->default('open'); // open, settled, waived
            $table->boolean('automatic_payroll_deduction')->default(false); // strictly prohibited per ASSET-02
            $table->uuid('assigned_by_user_id');
            $table->text('notes')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_asset_liabilities');
    }
};
