<?php

namespace Modules\Admin\Http\Controllers\Accountant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Services\BrandBranchResolver;
use Modules\Admin\Services\CustodyService;
use Modules\Admin\Support\CustodyStatus;
use Modules\Branch\Models\Branch;

/**
 * Cash custody management (SRS §7 ACC-8 / §8 HEAD-4). Serves both the platform
 * (`/accountant/*`, accountant+head) and company (`/company/me/*`) surfaces.
 */
class CashCustodyController extends AsabController
{
    public function __construct(
        private readonly CustodyService $custody,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * ACC-8.1 list: derived status per row + KPI header, paginated.
     * Filters: `brandId` (العلامة التجارية), `branchId` (الفرع), `status`
     * (حالة العهدة: normal|low|critical), `q` (branch or custodian name).
     */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'status' => 'sometimes|nullable|in:'.implode(',', CustodyStatus::keys()),
            ]);

            // KPIs reflect the whole assigned scope (not the filtered view).
            $scopeBase = $this->scopeToAssignedBranches(CashCustody::query());
            $kpiSet = (clone $scopeBase)->get(['id', 'branch_id', 'amount', 'used', 'min_alert']);
            $kpis = $this->custody->kpis($kpiSet);

            $q = clone $scopeBase;
            // Brand → branches resolves through the restaurant too (2026-08-03 rule).
            $this->brandBranches->applyFilter($q, $request->query('brandId'));
            if ($branch = $request->query('branchId')) {
                $q->where('branch_id', $branch);
            }
            // Derived from the live balance, not the stored column (legacy rows).
            $this->custody->applyStatusFilter($q, $request->query('status'));
            if ($needle = trim((string) $request->query('q', ''))) {
                $branchIds = Branch::where('name', 'like', '%'.$needle.'%')->pluck('id');
                $q->where(fn ($sub) => $sub->where('custodian_name', 'like', '%'.$needle.'%')
                    ->orWhereIn('branch_id', $branchIds));
            }

            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = $q->with('transactions')->orderByDesc('created_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            $branchNames = $this->custody->branchNames(collect($p->items())->pluck('branch_id'));
            $data = array_map(fn (CashCustody $c) => $this->custody->present($c, $branchNames), $p->items());

            return $this->paginated($p, $data, ['kpis' => $kpis]);
        });
    }

    public function settlementRequest(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $custody = $this->scopeToAssignedBranches(CashCustody::query())->findOrFail($id);
            $req = SettlementRequest::create([
                'custody_id' => $custody->id,
                'requested_by_id' => $request->user()->id,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            return $this->created(['id' => $req->id, 'status' => 'pending']);
        });
    }

    /**
     * Post a custody transaction. A credit raises the custody amount (تعزيز /
     * replenish); a debit is a disbursement. `status=pending` records the txn
     * without touching balances (a disbursement request awaiting approval);
     * `approved` (default) applies the effect immediately. Debits are guarded
     * against overdrawing the remaining balance.
     */
    public function addTransaction(Request $request, string $id): JsonResponse
    {
        return $this->fail(
            'CUSTODY_MANUAL_WRITE_PROHIBITED',
            'Manual custody transaction writes are prohibited under interim policy FUND-04/06.',
            'تعديل حركات العهدة اليدوية محظور بموجب السياسة المؤقتة',
            [],
            403
        );
    }
}
