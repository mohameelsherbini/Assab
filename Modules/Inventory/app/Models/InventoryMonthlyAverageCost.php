<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class InventoryMonthlyAverageCost extends Model
{
    use HasUuids;

    protected $table = 'inventory_monthly_average_costs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'item_id',
        'year_month',
        'beginning_qty',
        'beginning_cost_halalas',
        'received_qty',
        'received_cost_halalas',
        'unit_cost_halalas',
        'is_closed',
    ];

    protected $casts = [
        'beginning_qty' => 'decimal:4',
        'beginning_cost_halalas' => 'integer',
        'received_qty' => 'decimal:4',
        'received_cost_halalas' => 'integer',
        'unit_cost_halalas' => 'integer',
        'is_closed' => 'boolean',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
