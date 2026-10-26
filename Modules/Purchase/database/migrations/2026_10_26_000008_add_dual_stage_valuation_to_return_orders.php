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
        Schema::table('return_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('return_orders', 'accountant_reviewed_by')) {
                $table->uuid('accountant_reviewed_by')->nullable()->after('status');
            }
            if (! Schema::hasColumn('return_orders', 'accountant_reviewed_at')) {
                $table->timestamp('accountant_reviewed_at')->nullable()->after('accountant_reviewed_by');
            }
            if (! Schema::hasColumn('return_orders', 'verified_return_amount')) {
                $table->decimal('verified_return_amount', 12, 2)->nullable()->after('accountant_reviewed_at');
            }
            if (! Schema::hasColumn('return_orders', 'accountant_review_notes')) {
                $table->text('accountant_review_notes')->nullable()->after('verified_return_amount');
            }
            if (! Schema::hasColumn('return_orders', 'head_accountant_approved_by')) {
                $table->uuid('head_accountant_approved_by')->nullable()->after('accountant_review_notes');
            }
            if (! Schema::hasColumn('return_orders', 'head_accountant_approved_at')) {
                $table->timestamp('head_accountant_approved_at')->nullable()->after('head_accountant_approved_by');
            }
            if (! Schema::hasColumn('return_orders', 'is_financial_posted')) {
                $table->boolean('is_financial_posted')->default(false)->after('head_accountant_approved_at');
            }
            if (! Schema::hasColumn('return_orders', 'valuation_status')) {
                $table->string('valuation_status', 32)->default('unreviewed')->after('is_financial_posted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('return_orders', function (Blueprint $table) {
            $table->dropColumn([
                'accountant_reviewed_by',
                'accountant_reviewed_at',
                'verified_return_amount',
                'accountant_review_notes',
                'head_accountant_approved_by',
                'head_accountant_approved_at',
                'is_financial_posted',
                'valuation_status',
            ]);
        });
    }
};
