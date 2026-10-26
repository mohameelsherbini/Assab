<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceImport extends Model
{
    use HasUuids;

    protected $table = 'opening_balance_imports';

    protected $fillable = [
        'company_id',
        'branch_id',
        'file_hash',
        'filename',
        'imported_by_user_id',
        'rows_count',
        'total_debit_halalas',
        'total_credit_halalas',
        'status',
        'metadata',
    ];

    protected $casts = [
        'rows_count' => 'integer',
        'total_debit_halalas' => 'integer',
        'total_credit_halalas' => 'integer',
        'metadata' => 'array',
    ];
}
