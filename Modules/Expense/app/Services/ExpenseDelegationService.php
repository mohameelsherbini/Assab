<?php

namespace Modules\Expense\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\BranchCashierExpenseDelegation;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExpenseDelegationService
{
    /**
     * Resolve the acting branch manager ID for expense operations.
     * If actor is a Cashier with active delegation, returns the delegating Branch Manager's ID.
     * Otherwise returns auth()->id().
     */
    public static function resolveActingBranchManagerId(): ?string
    {
        $user = auth()->user();
        if ($user instanceof Cashier) {
            $delegation = BranchCashierExpenseDelegation::where('cashier_user_id', $user->id)
                ->where('branch_id', $user->branch_id)
                ->where('is_active', true)
                ->first();

            return $delegation?->granted_by_user_id;
        }

        return $user?->id;
    }

    /**
     * Check if current actor can modify/submit an expense belonging to a branch manager.
     */
    public static function isAuthorizedForExpense(?string $expenseBranchManagerId): bool
    {
        if ($expenseBranchManagerId === null) {
            return false;
        }

        $actingManagerId = self::resolveActingBranchManagerId();

        return $actingManagerId !== null && (string) $expenseBranchManagerId === (string) $actingManagerId;
    }

    /**
     * Grant expense write delegation to a cashier within the same branch.
     *
     * @throws HttpException
     */
    public function grantDelegation(BranchManager $manager, Cashier $cashier): BranchCashierExpenseDelegation
    {
        if ((string) $cashier->branch_id !== (string) $manager->branch_id) {
            throw new HttpException(403, 'Cross-branch expense delegation is prohibited.');
        }

        $origin = ExpenseOriginGuard::resolveForManager($manager->id);
        $companyId = $origin['company_id'] ?? $manager->branch?->asab_company_id ?? $cashier->branch?->asab_company_id;

        return BranchCashierExpenseDelegation::updateOrCreate(
            [
                'branch_id' => $manager->branch_id,
                'cashier_user_id' => $cashier->id,
            ],
            [
                'company_id' => $companyId,
                'granted_by_user_id' => $manager->id,
                'is_active' => true,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
            ]
        );
    }

    /**
     * Revoke expense write delegation from a cashier.
     *
     * @throws HttpException
     */
    public function revokeDelegation(BranchManager $manager, Cashier $cashier): ?BranchCashierExpenseDelegation
    {
        if ((string) $cashier->branch_id !== (string) $manager->branch_id) {
            throw new HttpException(403, 'Cross-branch expense delegation alteration is prohibited.');
        }

        $delegation = BranchCashierExpenseDelegation::where('branch_id', $manager->branch_id)
            ->where('cashier_user_id', $cashier->id)
            ->first();

        if ($delegation) {
            $delegation->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoked_by_user_id' => $manager->id,
            ]);
        }

        return $delegation;
    }

    /**
     * Check if a cashier holds an active expense write delegation in their branch.
     */
    public function isCashierDelegated(Cashier $cashier): bool
    {
        return BranchCashierExpenseDelegation::where('cashier_user_id', $cashier->id)
            ->where('branch_id', $cashier->branch_id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Retrieve the active delegation record for a cashier.
     */
    public function getActiveDelegation(Cashier $cashier): ?BranchCashierExpenseDelegation
    {
        return BranchCashierExpenseDelegation::where('cashier_user_id', $cashier->id)
            ->where('branch_id', $cashier->branch_id)
            ->where('is_active', true)
            ->first();
    }
}
