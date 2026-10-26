<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmployeeAssetLiability extends Model
{
    use HasUuids;

    protected $table = 'employee_asset_liabilities';

    protected $fillable = [
        'company_id',
        'branch_id',
        'employee_id',
        'asset_id',
        'registered_value_halalas',
        'liability_status',
        'automatic_payroll_deduction',
        'assigned_by_user_id',
        'notes',
        'assigned_at',
    ];

    protected $casts = [
        'registered_value_halalas' => 'integer',
        'automatic_payroll_deduction' => 'boolean',
        'assigned_at' => 'datetime',
    ];
}
