<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\ReturnOrder;

class PurchaseReturnService
{
    /**
     * Stage 1: Accountant reviews and verifies purchase return valuation (PUR-04, BE-26).
     */
    public function reviewReturnValuation(
        ReturnOrder $returnOrder,
        User $accountant,
        float $verifiedAmount,
        ?string $notes = null,
        ?string $companyId = null
    ): ReturnOrder {
        if (! $this->isAuthorizedAccountant($accountant, $companyId)) {
            throw new DomainException('Only an Accountant is authorized to review purchase return valuations.');
        }

        return DB::transaction(function () use ($returnOrder, $accountant, $verifiedAmount, $notes) {
            $returnOrder->update([
                'accountant_reviewed_by' => $accountant->id,
                'accountant_reviewed_at' => now(),
                'verified_return_amount' => $verifiedAmount,
                'accountant_review_notes' => $notes,
                'valuation_status' => 'reviewed',
                'is_financial_posted' => false,
            ]);

            return $returnOrder->fresh();
        });
    }

    /**
     * Stage 2: Head Accountant approves and financially posts purchase return (PUR-04, BE-26).
     */
    public function approveReturnPosting(
        ReturnOrder $returnOrder,
        User $headAccountant,
        ?string $approvalNotes = null,
        ?string $companyId = null
    ): ReturnOrder {
        if (! $this->isAuthorizedHeadAccountant($headAccountant, $companyId)) {
            throw new DomainException('Only Head Accountant is authorized to approve and post purchase return valuations.');
        }

        if ($returnOrder->valuation_status !== 'reviewed' || ! $returnOrder->accountant_reviewed_at) {
            throw new DomainException('Purchase return valuation must be reviewed by an Accountant before Head Accountant approval.');
        }

        return DB::transaction(function () use ($returnOrder, $headAccountant) {
            $returnOrder->update([
                'head_accountant_approved_by' => $headAccountant->id,
                'head_accountant_approved_at' => now(),
                'is_financial_posted' => true,
                'valuation_status' => 'approved',
                'status' => ReturnStatus::APPROVED,
                'approved_at' => now(),
            ]);

            return $returnOrder->fresh();
        });
    }

    /**
     * Check if user is an Accountant or higher financial authority.
     */
    public function isAuthorizedAccountant(User $user, ?string $companyId = null): bool
    {
        $allowedRoles = [
            'accountant',
            'head_accountant',
            'head',
            'admin',
            'general_manager',
            'super_admin',
        ];

        return $this->matchesRole($user, $allowedRoles, $companyId);
    }

    /**
     * Check if user is a Head Accountant or higher financial authority.
     */
    public function isAuthorizedHeadAccountant(User $user, ?string $companyId = null): bool
    {
        $allowedRoles = [
            'head_accountant',
            'head',
            'admin',
            'general_manager',
            'super_admin',
        ];

        return $this->matchesRole($user, $allowedRoles, $companyId);
    }

    private function matchesRole(User $user, array $allowedRoles, ?string $companyId = null): bool
    {
        // 1. Check CompanyUser
        $cuQuery = CompanyUser::where('user_id', $user->id)
            ->where('status', '!=', 'inactive')
            ->where('status', '!=', 'archived');

        if ($companyId) {
            $cuQuery->where('company_id', $companyId);
        }

        $cu = $cuQuery->first();
        if ($cu && in_array(strtolower((string) $cu->role_key), $allowedRoles, true)) {
            return true;
        }

        // 2. Check AsabUserRole
        $userRole = AsabUserRole::where('user_id', $user->id)->first();
        if ($userRole && in_array(strtolower((string) $userRole->role_key), $allowedRoles, true)) {
            return true;
        }

        // 3. Check direct roles collection if available
        if (isset($user->roles) && is_iterable($user->roles)) {
            $roles = collect($user->roles)->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
            foreach ($allowedRoles as $authRole) {
                if (in_array($authRole, $roles, true)) {
                    return true;
                }
            }
        }

        // 4. Check user attribute
        if (isset($user->role) && in_array(strtolower((string) $user->role), $allowedRoles, true)) {
            return true;
        }

        return false;
    }
}
