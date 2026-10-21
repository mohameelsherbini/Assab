<?php

namespace Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\TenantCompanyResolver;
use Modules\Admin\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Populates the request-scoped TenantContext from the authenticated AsabUser:
 * company_id + the user's primary role scope (brand/restaurant/branch ids,
 * module keys). Aborts with 403 WRONG_ROLE when an authenticated user has no
 * valid business role assignment (Fail-Closed Option A, Finding F2-A).
 * Aborts with 403 WRONG_TENANT when a non-admin user has no company — EXCEPT the
 * platform roles (supplier, procurement manager), which trade with ASAB rather
 * than with one company and so legitimately carry none.
 */
class ResolveTenant
{
    private const RECOGNIZED_ROLES = [
        'admin',
        'company-admin',
        'head',
        'accountant',
        'branch',
        'procurement',
        'supplier',
        'brand-owner',
        'brand-manager',
    ];

    private const RECOGNIZED_SCOPES = [
        'all',
        'brand',
        'restaurant',
        'branch',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof AsabUser) {
            return response()->json([
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required', 'messageAr' => 'يلزم تسجيل الدخول'],
                'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
            ], 401);
        }

        // F2-A: Validate authoritative business role assignment (Option A — Fail Closed).
        // The first persisted assignment defines the primary authoritative grant.
        $assignment = $user->roleAssignments->first();

        if (! $this->isValidAssignment($assignment)) {
            return response()->json([
                'error' => [
                    'code' => 'WRONG_ROLE',
                    'message' => 'You do not have permission to access this resource',
                    'messageAr' => 'ليس لديك صلاحية للوصول',
                ],
                'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
            ], 403);
        }

        $ctx = app(TenantContext::class);
        $ctx->resolved = true;
        $ctx->companyId = $user->company_id;
        $ctx->isAdmin = $user->hasAsabRole('admin');

        $ctx->roleKey = $assignment->role_key;
        $ctx->scope = $assignment->scope;
        $ctx->brandIds = $assignment->brand_ids ?? [];
        $ctx->restaurantIds = $assignment->restaurant_ids ?? [];
        $ctx->branchIds = $assignment->branch_ids ?? [];
        $ctx->moduleKeys = $assignment->module_keys ?? [];

        // A brand carries a company of its own, so an accountant holding two
        // brands legitimately spans two companies — the single company_id used
        // to hide the second one entirely. Platform roles are excluded: their
        // NULL company is the point, and deriving one would pin them to a tenant.
        if (! $ctx->isAdmin && ! in_array($ctx->roleKey, TenantContext::PLATFORM_ROLES, true)) {
            $ctx->companyIds = app(TenantCompanyResolver::class)
                ->resolve($ctx->companyId, $ctx->brandIds, $ctx->restaurantIds);

            // An account whose column was never filled but whose scope names a
            // company is no longer inert — same rule asab:repair-user-companies
            // applies, minus the wait for someone to run it.
            $ctx->companyId ??= $ctx->companyIds[0] ?? null;
        }

        // A supplier or procurement manager with no company is a PLATFORM
        // account, not a misconfigured tenant one: they work with ASAB and serve
        // every company. Anyone else without a company is still refused — the
        // check below is the only thing standing between a companyless user and
        // the global scope, which does not filter when there is no tenant.
        $ctx->isPlatform = ! $ctx->hasTenant()
            && in_array($ctx->roleKey, TenantContext::PLATFORM_ROLES, true);

        // The /company/me surface answers "my company" and reads
        // $user->company_id directly in dozens of controllers, so a platform
        // account carrying none has no business there — it would read those
        // NULLs as "no filter". Checked by path rather than by adding a second
        // middleware to each group: a new company route then inherits the guard
        // instead of silently missing it.
        $onCompanySurface = $request->is('api/*/company/*');

        if (! $ctx->isAdmin && ! $ctx->hasTenant() && (! $ctx->isPlatform || $onCompanySurface)) {
            return response()->json([
                'error' => ['code' => 'WRONG_TENANT', 'message' => 'User is not attached to a company', 'messageAr' => 'المستخدم غير مرتبط بشركة'],
                'requestId' => 'req_'.strtoupper(bin2hex(random_bytes(6))),
            ], 403);
        }

        return $next($request);
    }

    private function isValidAssignment(?AsabUserRole $assignment): bool
    {
        if (! $assignment) {
            return false;
        }

        $roleKey = $assignment->role_key;
        if (! is_string($roleKey) || ! in_array($roleKey, self::RECOGNIZED_ROLES, true)) {
            return false;
        }

        $scope = $assignment->scope;
        if (! is_string($scope) || ! in_array($scope, self::RECOGNIZED_SCOPES, true)) {
            return false;
        }

        if (! $this->isValidStringArray($assignment->brand_ids) ||
            ! $this->isValidStringArray($assignment->restaurant_ids) ||
            ! $this->isValidStringArray($assignment->branch_ids) ||
            ! $this->isValidStringArray($assignment->module_keys)) {
            return false;
        }

        if ($scope !== 'all') {
            $brandIds = $assignment->brand_ids ?? [];
            $restaurantIds = $assignment->restaurant_ids ?? [];
            $branchIds = $assignment->branch_ids ?? [];

            if (empty($brandIds) && empty($restaurantIds) && empty($branchIds)) {
                return false;
            }
        }

        return true;
    }

    private function isValidStringArray(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $k => $item) {
            if (! is_int($k) || ! is_string($item) || trim($item) === '') {
                return false;
            }
        }

        return true;
    }
}
