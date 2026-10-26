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
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('waste_damage_reports', 'financial_recognized')) {
                $table->boolean('financial_recognized')->default(false)->after('status');
            }
            if (! Schema::hasColumn('waste_damage_reports', 'financial_recognized_at')) {
                $table->timestamp('financial_recognized_at')->nullable()->after('financial_recognized');
            }
            if (! Schema::hasColumn('waste_damage_reports', 'financial_approved_by')) {
                $table->uuid('financial_approved_by')->nullable()->after('financial_recognized_at');
            }
            if (! Schema::hasColumn('waste_damage_reports', 'total_financial_loss_halalas')) {
                $table->unsignedBigInteger('total_financial_loss_halalas')->default(0)->after('financial_approved_by');
            }
        });

        Schema::table('waste_damage_report_items', function (Blueprint $table) {
            if (! Schema::hasColumn('waste_damage_report_items', 'unit_cost_halalas')) {
                $table->unsignedBigInteger('unit_cost_halalas')->default(0)->after('price_per_unit');
            }
            if (! Schema::hasColumn('waste_damage_report_items', 'total_cost_halalas')) {
                $table->unsignedBigInteger('total_cost_halalas')->default(0)->after('unit_cost_halalas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waste_damage_reports', function (Blueprint $table) {
            $table->dropColumn([
                'financial_recognized',
                'financial_recognized_at',
                'financial_approved_by',
                'total_financial_loss_halalas',
            ]);
        });

        Schema::table('waste_damage_report_items', function (Blueprint $table) {
            $table->dropColumn([
                'unit_cost_halalas',
                'total_cost_halalas',
            ]);
        });
    }
};
