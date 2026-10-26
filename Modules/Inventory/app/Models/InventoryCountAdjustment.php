<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class InventoryCountAdjustment extends Model
{
    use HasUuids;

    protected $table = 'inventory_count_adjustments';

    protected $fillable = [
        'company_id',
        'branch_id',
        'item_id',
        'session_id',
        'adjustment_type',
        'system_quantity',
        'counted_quantity',
        'variance_quantity',
        'unit_cost_halalas',
        'total_variance_cost_halalas',
        'reason',
        'adjusted_by_user_id',
        'year_month',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:4',
        'counted_quantity' => 'decimal:4',
        'variance_quantity' => 'decimal:4',
        'unit_cost_halalas' => 'integer',
        'total_variance_cost_halalas' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
