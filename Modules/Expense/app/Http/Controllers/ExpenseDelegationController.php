<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Services\ExpenseDelegationService;

class ExpenseDelegationController extends Controller
{
    public function __construct(
        protected ExpenseDelegationService $delegationService
    ) {}

    /**
     * Grant expense write delegation to a cashier within the same branch.
     * POST /api/branch-manager/cashiers/{cashier}/expense-delegation/grant
     */
    public function grant(Request $request, string $cashierId): JsonResponse
    {
        $manager = auth()->user();
        if (! ($manager instanceof BranchManager)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Branch Managers may grant expense delegation.',
                'errors' => ['code' => 'UNAUTHORIZED_ROLE'],
            ], 403);
        }

        $cashier = Cashier::findOrFail($cashierId);

        if ((string) $cashier->branch_id !== (string) $manager->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cross-branch expense delegation is prohibited.',
                'errors' => ['code' => 'CROSS_BRANCH_DELEGATION_PROHIBITED'],
            ], 403);
        }

        $delegation = $this->delegationService->grantDelegation($manager, $cashier);

        return response()->json([
            'success' => true,
            'message' => 'Expense write delegation granted successfully.',
            'data' => $delegation,
        ], 200);
    }

    /**
     * Revoke expense write delegation from a cashier.
     * POST /api/branch-manager/cashiers/{cashier}/expense-delegation/revoke
     */
    public function revoke(Request $request, string $cashierId): JsonResponse
    {
        $manager = auth()->user();
        if (! ($manager instanceof BranchManager)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Branch Managers may revoke expense delegation.',
                'errors' => ['code' => 'UNAUTHORIZED_ROLE'],
            ], 403);
        }

        $cashier = Cashier::findOrFail($cashierId);

        if ((string) $cashier->branch_id !== (string) $manager->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cross-branch expense delegation alteration is prohibited.',
                'errors' => ['code' => 'CROSS_BRANCH_DELEGATION_PROHIBITED'],
            ], 403);
        }

        $delegation = $this->delegationService->revokeDelegation($manager, $cashier);

        return response()->json([
            'success' => true,
            'message' => 'Expense write delegation revoked successfully.',
            'data' => $delegation,
        ], 200);
    }

    /**
     * Get delegation status for a cashier.
     * GET /api/branch-manager/cashiers/{cashier}/expense-delegation/status
     */
    public function status(Request $request, string $cashierId): JsonResponse
    {
        $cashier = Cashier::findOrFail($cashierId);
        $delegation = $this->delegationService->getActiveDelegation($cashier);

        return response()->json([
            'success' => true,
            'is_delegated' => $delegation !== null && $delegation->is_active,
            'data' => $delegation,
        ]);
    }
}
