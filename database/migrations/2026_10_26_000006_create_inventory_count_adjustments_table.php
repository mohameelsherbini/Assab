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
        Schema::create('inventory_count_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('branch_id')->index();
            $table->uuid('item_id')->index();
            $table->uuid('session_id')->nullable()->index();
            $table->string('adjustment_type', 32); // 'shortage' or 'surplus'
            $table->decimal('system_quantity', 12, 4);
            $table->decimal('counted_quantity', 12, 4);
            $table->decimal('variance_quantity', 12, 4);
            $table->unsignedBigInteger('unit_cost_halalas')->default(0);
            $table->unsignedBigInteger('total_variance_cost_halalas')->default(0);
            $table->string('reason')->nullable();
            $table->uuid('adjusted_by_user_id')->nullable();
            $table->string('year_month', 7)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_count_adjustments');
    }
};
