<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_monthly_average_costs')) {
            Schema::create('inventory_monthly_average_costs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('branch_id')->index();
                $table->uuid('item_id')->index();
                $table->string('year_month', 7)->index();
                $table->decimal('beginning_qty', 14, 4)->default(0);
                $table->unsignedBigInteger('beginning_cost_halalas')->default(0);
                $table->decimal('received_qty', 14, 4)->default(0);
                $table->unsignedBigInteger('received_cost_halalas')->default(0);
                $table->unsignedBigInteger('unit_cost_halalas')->default(0);
                $table->boolean('is_closed')->default(false);
                $table->timestamps();

                $table->unique(['company_id', 'branch_id', 'item_id', 'year_month'], 'inv_monthly_avg_cost_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_monthly_average_costs');
    }
};
