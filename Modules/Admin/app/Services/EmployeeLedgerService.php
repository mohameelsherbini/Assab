<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Support\EmployeeMovementCategory as Cat;

/**
 * SRS §7 ACC-7 — the read/write core of the employee account ledger: signed
 * balances for the master list, the month-bounded statement with a per-row
 * running balance, manual movements (category-validated), and «تسوية الرصيد».
 *
 * Balance convention (SRS §6): balance = Σcredit − Σdebit. A negative balance
 * means the employee owes the company («مديون للشركة»).
 */
class EmployeeLedgerService
{
    /**
     * Signed standing balance (all-time) for many employees in one query.
     * CASE-WHEN aggregation is identical on MySQL and SQLite.
     *
     * @param  iterable<string>  $employeeIds
     * @return array<string,int> employeeId → balance halalas
     */
    public function balancesFor(iterable $employeeIds): array
    {
        $ids = collect($employeeIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return EmployeeMovement::whereIn('employee_id', $ids)
            ->selectRaw("employee_id, SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END) as bal")
            ->groupBy('employee_id')
            ->pluck('bal', 'employee_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** The one employee's signed standing balance (all movements). */
    public function currentBalance(string $employeeId): int
    {
        return $this->balancesFor([$employeeId])[$employeeId] ?? 0;
    }

    /**
     * ACC-7.2 statement: opening balance carried from before the period, each
     * movement's running balance, the closing balance, and the auto-deduction
     * banner flag (keyed off the *current* standing balance, not the period).
     *
     * @return array<string,mixed>
     */
    public function statement(Employee $employee, ?string $month, int $page = 1, int $pageSize = 50): array
    {
        $pageSize = max(1, min($pageSize, 200));
        [$start, $end, $monthKey] = $this->period($month);

        $base = EmployeeMovement::where('employee_id', $employee->id);

        // Opening balance = net of everything strictly before the period window.
        $opening = $month
            ? (int) (clone $base)->where('movement_date', '<', $start)
                ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END), 0) as bal")
                ->value('bal')
            : 0;

        $windowed = (clone $base)
            ->when($month, fn ($q) => $q->whereBetween('movement_date', [$start, $end]));

        // Running balance is a full-period scan (small per month); compute it over
        // the whole ascending set so every page carries the correct figure, then
        // slice for display. Hard cap guards the unbounded case.
        $asc = (clone $windowed)->orderBy('movement_date')->orderBy('id')->limit(2000)->get();
        $total = $asc->count();

        $running = $opening;
        $all = [];
        foreach ($asc as $m) {
            $running += $m->movement_type === 'credit' ? (int) $m->amount : -(int) $m->amount;
            $all[] = [
                'id' => $m->id,
                'movementDate' => optional($m->movement_date)->toIso8601String(),
                'description' => $m->description,
                'movementType' => $m->movement_type,
                'movementTypeLabelAr' => $m->movement_type === 'credit' ? 'دائن' : 'مدين',
                'category' => $m->category,
                'categoryLabelAr' => Cat::labelAr($m->category),
                'ref' => $m->ref,
                'amountHalalas' => (int) $m->amount,
                'amount' => (int) $m->amount, // legacy alias
                'runningBalanceHalalas' => $running,
            ];
        }
        $closing = $running;
        // Newest-first for display, then paginate the slice.
        $rows = array_slice(array_reverse($all), ($page - 1) * $pageSize, $pageSize);

        $current = $this->currentBalance($employee->id);

        $pendingSettlement = \Modules\Admin\Models\EmployeeSettlementRequest::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();

        $latestSettlement = $pendingSettlement ?? \Modules\Admin\Models\EmployeeSettlementRequest::where('employee_id', $employee->id)
            ->latest('created_at')
            ->first();

        $settlementStatus = $pendingSettlement
            ? 'under_review'
            : ($latestSettlement?->status === 'approved' ? 'approved' : null);

        return [
            'employee' => [
                'id' => $employee->id, 'name' => $employee->name,
                'empNumber' => $employee->emp_number, 'branchId' => $employee->branch_id,
            ],
            'period' => ['month' => $monthKey, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
            'openingBalanceHalalas' => $opening,
            'closingBalanceHalalas' => $closing,
            // Standing all-time balance — the master-list figure + banner source.
            'balance' => $current,
            'balanceHalalas' => $current,
            'balanceCaption' => Cat::balanceCaption($current),
            'hasDebitBalance' => $current < 0,
            'autoDeductFromSalary' => $current < 0,
            'settlementStatus' => $settlementStatus,
            'totalCredit' => (int) (clone $windowed)->where('movement_type', 'credit')->sum('amount'),
            'totalDebit' => (int) (clone $windowed)->where('movement_type', 'debit')->sum('amount'),
            'movementCount' => $total,
            'movements' => $rows,
            'meta' => [
                'page' => $page, 'pageSize' => $pageSize, 'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ];
    }

    /**
     * ACC-7.4 — post one manual movement. System categories are rejected: a
     * sales/cash/waste/inventory charge may only enter through its operation flow.
     *
     * @param  array{movementType:string, amount:int, category:string, description:string, date?:string|null}  $data
     */
    public function addMovement(Employee $employee, array $data, AsabUser $actor): EmployeeMovement
    {
        $category = $data['category'];
        if (! Cat::isManual($category)) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'Unknown or system-managed category',
                'تصنيف غير معروف أو خاص بالنظام',
                422,
                ['category' => ['must be one of: '.implode(', ', Cat::manualKeys())]],
            );
        }

        return EmployeeMovement::create([
            'employee_id' => $employee->id,
            'movement_type' => $data['movementType'],
            'amount' => (int) $data['amount'],
            'category' => $category,
            'ref' => Cat::makeRef($category, (string) Str::uuid()),
            'description' => $data['description'],
            'movement_date' => $data['date'] ?? now(),
            'created_by_id' => $actor->id,
        ]);
    }

    /**
     * ACC-7.3 / STMT-02 / BE-09 «تسوية الرصيد» — creates a pending settlement request.
     * Standing balance is NOT credited until Head Accountant approval.
     *
     * @return array<string,mixed>
     */
    public function settleBalance(Employee $employee, ?int $amount, AsabUser $actor, string $reason, ?string $idempotencyKey = null): array
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '' || mb_strlen($trimmedReason) < 3) {
            throw new AsabException(
                'VALIDATION_ERROR',
                'A valid reason is mandatory for balance settlement',
                'سبب التسوية إلزامي',
                422,
                ['reason' => ['Reason must be at least 3 characters']],
            );
        }

        if ($idempotencyKey) {
            $existing = \Modules\Admin\Models\EmployeeSettlementRequest::where('company_id', $employee->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                // The replay identity is scoped to the employee (resource): a key
                // bound to another employee must conflict, never leak that request.
                if ((string) $existing->employee_id !== (string) $employee->id) {
                    throw new AsabException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'Idempotency-Key is already bound to another employee settlement',
                        'مفتاح التكرار مرتبط بتسوية موظف آخر',
                        409,
                    );
                }

                if (! $this->sameSettlementPayload($existing, $amount, $trimmedReason)) {
                    throw new AsabException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'Idempotency-Key was reused with a different payload',
                        'تمت إعادة استخدام مفتاح التكرار بحمولة مختلفة',
                        409,
                    );
                }

                return $this->formatSettlementResponse($existing, $this->currentBalance($employee->id));
            }
        }

        return DB::transaction(function () use ($employee, $amount, $actor, $trimmedReason, $idempotencyKey) {
            // Serialize against settlements approval on the same financial source.
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $balance = $this->currentBalance($employee->id);
            if ($balance === 0) {
                throw new AsabException('BALANCE_ALREADY_SETTLED', 'Balance is already zero', 'الرصيد صفر بالفعل', 409);
            }

            $magnitude = abs($balance);
            $settle = $amount !== null ? (int) $amount : $magnitude;
            if ($settle < 1 || $settle > $magnitude) {
                throw new AsabException(
                    'VALIDATION_ERROR',
                    'Settlement amount must be between 1 and the outstanding balance',
                    'قيمة التسوية يجب أن تكون بين 1 والرصيد القائم',
                    422,
                    ['amountHalalas' => ["outstanding {$magnitude} halalas"]],
                );
            }

            $request = \Modules\Admin\Models\EmployeeSettlementRequest::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'amount_halalas' => $settle,
                'reason' => $trimmedReason,
                'status' => 'pending',
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $this->formatSettlementResponse($request, $balance);
        });
    }

    /**
     * BE-09 / STMT-02 Head Accountant approval: posts the opposing ledger movement and marks request approved.
     *
     * @return array<string,mixed>
     */
    public function approveSettlement(string $id, AsabUser $reviewer): array
    {
        return DB::transaction(function () use ($id, $reviewer) {
            // Lock order (employee → request) matches settleBalance creation so
            // concurrent creation/approval for one employee never deadlocks.
            $req = \Modules\Admin\Models\EmployeeSettlementRequest::where('company_id', $reviewer->company_id)
                ->findOrFail($id);
            $employee = Employee::whereKey($req->employee_id)->lockForUpdate()->firstOrFail();
            $req = \Modules\Admin\Models\EmployeeSettlementRequest::whereKey($req->id)
                ->lockForUpdate()
                ->first();

            if ($req === null || $req->status !== 'pending') {
                throw new AsabException('REQUEST_ALREADY_PROCESSED', 'Settlement request is already '.($req->status ?? 'processed'), 'تمت معالجة طلب التسوية مسبقاً', 409);
            }

            $balance = $this->currentBalance($req->employee_id);
            $amount = (int) $req->amount_halalas;

            // Stale revalidation: the request may only post while the outstanding
            // balance still covers its amount in the direction it was created for.
            if ($balance === 0) {
                throw new AsabException('BALANCE_ALREADY_SETTLED', 'Employee balance is already zero; this settlement request is stale and must be resubmitted', 'الرصيد صفر بالفعل — طلب التسوية قديم ويجب إعادة إنشائه', 409);
            }

            if (abs($balance) < $amount) {
                throw new AsabException('SETTLEMENT_BALANCE_CHANGED', 'The outstanding balance no longer covers this settlement request; it is stale and must be resubmitted', 'الرصيد القائم لم يعد يغطي طلب التسوية — الطلب قديم ويجب إعادة إنشائه', 409);
            }

            $creationBalance = $this->balanceAt($req->employee_id, $req->created_at);
            if ($creationBalance !== 0 && ($balance < 0) !== ($creationBalance < 0)) {
                throw new AsabException('SETTLEMENT_DIRECTION_CHANGED', 'The balance direction changed since this request was created; it is stale and must be resubmitted', 'اتجاه الرصيد تغيّر بعد إنشاء الطلب — الطلب قديم ويجب إعادة إنشائه', 409);
            }

            // Debtor (negative) → credit clears it; creditor (positive) → debit.
            // The revalidation above guarantees the posting moves toward zero and
            // never recreates debt in either direction.
            $type = $balance < 0 ? 'credit' : 'debit';
            $movement = EmployeeMovement::create([
                'employee_id' => $req->employee_id,
                'movement_type' => $type,
                'amount' => $req->amount_halalas,
                'category' => 'settlement',
                'ref' => Cat::makeRef('settlement', (string) Str::uuid()),
                'description' => 'تسوية رصيد معتمدة — '.$employee->name.' — '.$req->reason,
                'movement_date' => now(),
                'created_by_id' => $reviewer->id,
            ]);

            $req->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'movement_id' => $movement->id,
            ]);

            $newBalance = $this->currentBalance($req->employee_id);

            $res = $this->formatSettlementResponse($req, $newBalance);
            $res['movementType'] = $type;
            $res['category'] = 'settlement';
            $res['categoryLabelAr'] = Cat::labelAr('settlement');
            $res['ref'] = $movement->ref;

            return $res;
        });
    }

    /**
     * BE-09 / STMT-02 Head Accountant rejection: transitions status to rejected without ledger posting.
     *
     * @return array<string,mixed>
     */
    public function rejectSettlement(string $id, ?string $rejectionReason, AsabUser $reviewer): array
    {
        return DB::transaction(function () use ($id, $rejectionReason, $reviewer) {
            $req = \Modules\Admin\Models\EmployeeSettlementRequest::where('company_id', $reviewer->company_id)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($req->status !== 'pending') {
                throw new AsabException('REQUEST_ALREADY_PROCESSED', 'Settlement request is already '.$req->status, 'تمت معالجة طلب التسوية مسبقاً', 409);
            }

            $req->update([
                'status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            return $this->formatSettlementResponse($req);
        });
    }

    /**
     * Canonical replay comparison for a reused settlement idempotency key: the
     * employee is matched by the lookup itself; here the reason and effective
     * settlement amount must be identical before the existing request may be replayed.
     * When $amount is null (meaning "full outstanding balance"), the requested
     * amount is the employee's standing balance magnitude at replay time; it must
     * strictly match the stored request amount to avoid replaying a partial settlement.
     */
    private function sameSettlementPayload(\Modules\Admin\Models\EmployeeSettlementRequest $existing, ?int $amount, string $reason): bool
    {
        if (trim($existing->reason ?? '') !== $reason) {
            return false;
        }

        $expectedAmount = $amount !== null ? (int) $amount : abs($this->currentBalance($existing->employee_id));

        return $expectedAmount > 0 && (int) $existing->amount_halalas === $expectedAmount;
    }

    /**
     * Signed balance restricted to movements recorded no later than $until.
     * Recovers a settlement request's creation-time direction from the ledger
     * itself, since the schema stores no direction column (BE-09 migration
     * is unapproved; a stored direction/balance column belongs to its review).
     */
    private function balanceAt(string $employeeId, $until): int
    {
        return (int) EmployeeMovement::where('employee_id', $employeeId)
            ->where('created_at', '<=', $until)
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'credit' THEN amount ELSE -amount END), 0) as bal")
            ->value('bal');
    }

    public function formatSettlementResponse(\Modules\Admin\Models\EmployeeSettlementRequest $req, ?int $balance = null): array
    {
        return [
            'id' => $req->id,
            'settlementId' => $req->id,
            'companyId' => $req->company_id,
            'employeeId' => $req->employee_id,
            'amountHalalas' => (int) $req->amount_halalas,
            'reason' => $req->reason,
            'status' => $req->status,
            'rejectionReason' => $req->rejection_reason,
            'createdByUserId' => $req->created_by_user_id,
            'reviewedByUserId' => $req->reviewed_by_user_id,
            'reviewedAt' => $req->reviewed_at?->toIso8601String(),
            'movementId' => $req->movement_id,
            'balanceModified' => $req->status === 'approved',
            'balanceHalalas' => $balance !== null ? $balance : $this->currentBalance($req->employee_id),
            'createdAt' => $req->created_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a YYYY-MM window. No month → the full range (a sentinel wide span
     * the caller treats as "all", with the current month echoed for display).
     *
     * @return array{0:Carbon,1:Carbon,2:string}
     */
    private function period(?string $month): array
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

            return [$start, (clone $start)->endOfMonth(), $month];
        }

        // "All" — span everything; echo the current month as the label.
        return [Carbon::createFromFormat('Y-m-d', '2000-01-01')->startOfDay(), now()->endOfMonth(), now()->format('Y-m')];
    }
}
