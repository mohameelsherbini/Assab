<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

class BranchCashierExpenseDelegation extends Model
{
    use HasUuids;

    protected $table = 'branch_cashier_expense_delegations';

    protected $fillable = [
        'company_id',
        'branch_id',
        'cashier_user_id',
        'granted_by_user_id',
        'is_active',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'revoked_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function cashier()
    {
        return $this->belongsTo(Cashier::class, 'cashier_user_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(BranchManager::class, 'granted_by_user_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(BranchManager::class, 'revoked_by_user_id');
    }
}
