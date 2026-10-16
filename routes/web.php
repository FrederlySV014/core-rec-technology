<?php

use App\Http\Controllers\Auth\CompanyInvitationController;
use App\Http\Controllers\Company\CompanyMemberController;
use App\Http\Controllers\Company\CompanyOwnershipTransferController;
use App\Http\Controllers\Company\CompanyRoleController;
use App\Http\Controllers\Platform\PlatformProvisioningController;
use App\Http\Controllers\Platform\PlatformStaffController;
use App\Models\User;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Services\PlatformAuthorizationService;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\Membership;
use App\Modules\Licensing\Services\LicenseResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::model('membership', Membership::class);
Route::model('role', CompanyRole::class);

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request, LicenseResolverService $licenseResolver, PlatformAuthorizationService $platformAuth) {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $memberships = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->with(['company', 'roles'])
            ->get()
            ->filter(fn ($m) => $m->company->administrative_status === CompanyStatus::Active)
            ->map(function ($membership) use ($licenseResolver) {
                $licenseResult = $licenseResolver->resolveEffectiveLicense($membership->company);

                return [
                    'id' => $membership->id,
                    'is_owner' => (bool) $membership->is_owner,
                    'company' => [
                        'id' => $membership->company->id,
                        'legal_name' => $membership->company->legal_name,
                        'trade_name' => $membership->company->trade_name,
                        'slug' => $membership->company->slug,
                        'country_code' => $membership->company->country_code,
                    ],
                    'roles' => $membership->roles->map(fn ($r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'slug' => $r->slug,
                    ]),
                    'license' => [
                        'status' => $licenseResult->validityStatus->value,
                        'is_read_only' => $licenseResult->isReadOnly,
                        'enabled_modules' => $licenseResult->enabledModuleSlugs,
                        'plan_name' => $licenseResult->license?->plan_name,
                    ],
                ];
            })
            ->values();

        return Inertia::render('dashboard', [
            'memberships' => $memberships,
            'canAccessPlatform' => $platformAuth->isOperator($user),
        ]);
    })->name('dashboard');

    // Platform Administration routes (scoped strictly to SaaS operators with specific permissions and 2FA)
    Route::prefix('platform')->middleware(['privileged.2fa:platform'])->group(function () {
        Route::get('companies', fn () => response()->json([
            'status' => 'ok',
            'message' => 'Catálogo central de empresas.',
        ]))->middleware('platform.can:platform.companies.view')->name('platform.companies.index');

        Route::post('companies', fn () => response()->json([
            'status' => 'ok',
            'message' => 'Empresa creada centralmente.',
        ]))->middleware('platform.can:platform.companies.manage')->name('platform.companies.store');

        Route::get('licenses', fn () => response()->json([
            'status' => 'ok',
            'message' => 'Gestión central de licencias.',
        ]))->middleware('platform.can:platform.licenses.view')->name('platform.licenses.index');

        Route::post('licenses/renew', fn () => response()->json([
            'status' => 'ok',
            'message' => 'Licencia renovada centralmente.',
        ]))->middleware('platform.can:platform.licenses.manage')->name('platform.licenses.renew');

        Route::get('staff', [PlatformStaffController::class, 'index'])
            ->middleware('platform.can:platform.staff.view')
            ->name('platform.staff.index');

        Route::post('staff', [PlatformStaffController::class, 'store'])
            ->middleware('platform.can:platform.staff.manage')
            ->name('platform.staff.store');

        Route::put('staff/{user}/roles', [PlatformStaffController::class, 'updateRoles'])
            ->middleware('platform.can:platform.staff.manage')
            ->name('platform.staff.roles.update');

        Route::post('staff/{user}/toggle-suspension', [PlatformStaffController::class, 'toggleSuspension'])
            ->middleware('platform.can:platform.staff.manage')
            ->name('platform.staff.suspension.toggle');

        Route::delete('staff/{user}', [PlatformStaffController::class, 'destroy'])
            ->middleware('platform.can:platform.staff.manage')
            ->name('platform.staff.destroy');

        Route::get('audit', [PlatformStaffController::class, 'audit'])
            ->middleware('platform.can:platform.audit.view')
            ->name('platform.audit.index');

        Route::get('companies/{company}/provisioning', [PlatformProvisioningController::class, 'status'])
            ->middleware('platform.can:platform.provisioning.view')
            ->name('platform.provisioning.status');

        Route::post('companies/{company}/provision', [PlatformProvisioningController::class, 'provision'])
            ->middleware('platform.can:platform.provisioning.manage')
            ->name('platform.provisioning.store');

        Route::post('companies/{company}/provision/retry', [PlatformProvisioningController::class, 'retry'])
            ->middleware('platform.can:platform.provisioning.retry')
            ->name('platform.provisioning.retry');
    });

    // Commercial Context Routes: /app/{company:slug}
    Route::prefix('app/{company:slug}')->middleware(['company.context', 'privileged.2fa:company'])->group(function () {
        Route::get('/', function (Request $request) {
            /** @var CompanyContext $context */
            $context = $request->attributes->get('company_context');

            return Inertia::render('company/workspace', [
                'company' => [
                    'legal_name' => $context->company->legal_name,
                    'trade_name' => $context->company->trade_name,
                    'slug' => $context->company->slug,
                ],
                'license' => [
                    'status' => $context->effectiveLicense->validityStatus->value,
                    'is_read_only' => $context->isReadOnly,
                    'plan_name' => $context->effectiveLicense->license?->plan_name,
                    'enabled_modules' => $context->enabledModuleSlugs,
                ],
                'effective_permissions' => $context->effectivePermissionSlugs,
            ]);
        })->name('company.workspace');

        // Commercial test endpoints to prove authorization enforcement
        Route::get('test/inventory/view', fn () => response()->json([
            'status' => 'ok',
            'action' => 'inventory.products.view',
        ]))->middleware('company.can:inventory.products.view,read_only');

        Route::post('test/inventory/manage', fn () => response()->json([
            'status' => 'ok',
            'action' => 'inventory.products.manage',
        ]))->middleware('company.can:inventory.products.manage,mutable');

        Route::post('test/reports/export', fn () => response()->json([
            'status' => 'ok',
            'action' => 'reports.sales.view',
        ]))->middleware('company.can:reports.sales.view,read_only');

        Route::post('test/sales/create', fn () => response()->json([
            'status' => 'ok',
            'action' => 'sales.orders.create',
        ]))->middleware('company.can:sales.orders.create,mutable');

        // Members & Invitations management with granular permissions
        Route::get('members', [CompanyMemberController::class, 'index'])
            ->middleware('company.can:commercial.members.view,security_admin')
            ->name('company.members.index');

        Route::post('members/invite', [CompanyMemberController::class, 'invite'])
            ->middleware('company.can:commercial.members.invite,security_admin')
            ->name('company.members.invite');

        Route::post('members/invitations/{invitationId}/resend', [CompanyMemberController::class, 'resend'])
            ->middleware('company.can:commercial.members.invite,security_admin')
            ->name('company.members.invitations.resend');

        Route::delete('members/invitations/{invitationId}', [CompanyMemberController::class, 'cancel'])
            ->middleware('company.can:commercial.members.invite,security_admin')
            ->name('company.members.invitations.cancel');

        Route::post('members/{membership}/toggle-suspension', [CompanyMemberController::class, 'toggleSuspension'])
            ->middleware('company.can:commercial.members.suspend|commercial.members.reactivate,security_admin')
            ->name('company.members.suspension.toggle');

        Route::delete('members/{membership}', [CompanyMemberController::class, 'destroy'])
            ->middleware('company.can:commercial.members.revoke,security_admin')
            ->name('company.members.destroy');

        Route::put('members/{membership}/roles', [CompanyMemberController::class, 'updateRoles'])
            ->middleware('company.can:commercial.members.assign_roles,security_admin')
            ->name('company.members.roles.update');

        // Company Roles CRUD with granular permissions
        Route::get('roles', [CompanyRoleController::class, 'index'])
            ->middleware('company.can:commercial.roles.view,security_admin')
            ->name('company.roles.index');

        Route::post('roles', [CompanyRoleController::class, 'store'])
            ->middleware('company.can:commercial.roles.create,security_admin')
            ->name('company.roles.store');

        Route::put('roles/{role}', [CompanyRoleController::class, 'update'])
            ->middleware('company.can:commercial.roles.update,security_admin')
            ->name('company.roles.update');

        Route::delete('roles/{role}', [CompanyRoleController::class, 'destroy'])
            ->middleware('company.can:commercial.roles.delete,security_admin')
            ->name('company.roles.destroy');

        // Ownership Transfer (initiated by owner, accepted by verified active member with 2FA)
        Route::post('ownership-transfers', [CompanyOwnershipTransferController::class, 'initiate'])
            ->middleware(['company.can:commercial.ownership.transfer,security_admin', 'password.confirm'])
            ->name('company.ownership-transfers.initiate');

        Route::post('ownership-transfers/cancel', [CompanyOwnershipTransferController::class, 'cancel'])
            ->middleware('company.can:commercial.ownership.transfer,security_admin')
            ->name('company.ownership-transfers.cancel');

        Route::get('ownership-transfers/{token}', [CompanyOwnershipTransferController::class, 'show'])
            ->name('company.ownership-transfers.show');

        Route::post('ownership-transfers/{token}/accept', [CompanyOwnershipTransferController::class, 'accept'])
            ->middleware('password.confirm')
            ->name('company.ownership-transfers.accept');
    });

    Route::post('invitations/{token}/accept', [CompanyInvitationController::class, 'accept'])->name('invitations.accept');
});

// Public Invitation acceptance routes
Route::get('invitations/{token}', [CompanyInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/register', [CompanyInvitationController::class, 'registerAndAccept'])->name('invitations.register');

require __DIR__.'/settings.php';
