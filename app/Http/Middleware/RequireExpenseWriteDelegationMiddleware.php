<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\BranchCashierExpenseDelegation;
use Symfony\Component\HttpFoundation\Response;

/**
 * BRC v3.4 EXP-09 — a cashier may create/submit/alter an expense ONLY when the Branch Manager has
 * delegated that permission to him; a cashier without it must not get the operation automatically.
 *
 * Applied to the expense WRITE routes that live behind `branch.manager.or.cashier`.
 * When active delegation exists in branch_cashier_expense_delegations for the cashier and branch,
 * the cashier is allowed to proceed.
 * A Branch Manager is never affected.
 */
class RequireExpenseWriteDelegationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user instanceof Cashier) {
            $isDelegated = BranchCashierExpenseDelegation::where('cashier_user_id', $user->id)
                ->where('branch_id', $user->branch_id)
                ->where('is_active', true)
                ->exists();

            if (! $isDelegated) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense creation and submission have not been delegated to cashiers. Ask your Branch Manager.',
                    'errors' => ['code' => 'EXPENSE_DELEGATION_REQUIRED'],
                ], 403);
            }
        }

        return $next($request);
    }
}
