<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Modules\Access\Models\PlatformUserRole;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Enums\CompanyStatus;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\Membership;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
            $user->sendEmailVerificationNotification();
        }

        $user->save();

        CentralAuditLog::create([
            'user_id' => $user->id,
            'action' => $emailChanged ? 'auth.email_updated' : 'auth.profile_updated',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile and close account without physically purging historical references.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        // 1. Prevent closure if user is the sole remaining active platform administrator
        $isPlatformAdmin = $user->platformRoles()
            ->whereNull('platform_user_roles.suspended_at')
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'platform.staff.manage'))
            ->exists();

        if ($isPlatformAdmin) {
            $otherPlatformAdmins = User::query()
                ->where('id', '!=', $user->id)
                ->where('status', UserStatus::Active)
                ->whereHas('platformRoles', function ($q) {
                    $q->whereNull('platform_user_roles.suspended_at')
                        ->whereHas('permissions', fn ($pq) => $pq->where('slug', 'platform.staff.manage'));
                })
                ->count();

            if ($otherPlatformAdmins === 0) {
                throw ValidationException::withMessages([
                    'password' => 'No es posible cerrar la cuenta: es el único administrador activo de la plataforma SaaS. Debe designar un reemplazo primero.',
                ]);
            }
        }

        // 2. Prevent closure if user is the active owner of any active company
        $ownedCompanies = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->where('is_owner', true)
            ->whereHas('company', fn ($q) => $q->where('administrative_status', CompanyStatus::Active))
            ->with('company')
            ->get();

        if ($ownedCompanies->isNotEmpty()) {
            $companyName = $ownedCompanies->first()->company->legal_name;
            throw ValidationException::withMessages([
                'password' => "No es posible cerrar la cuenta: es el propietario titular de la empresa '{$companyName}'. Debe transferir la titularidad formalmente antes de cerrar su cuenta.",
            ]);
        }

        // 3. Prevent closure if user is the sole remaining active company administrator in any company
        $adminMemberships = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', ['commercial.members.manage', 'commercial.members.assign_roles']))
            ->with('company')
            ->get();

        foreach ($adminMemberships as $m) {
            $otherCompanyAdmins = Membership::query()
                ->where('company_id', $m->company_id)
                ->where('id', '!=', $m->id)
                ->where('status', MembershipStatus::Active)
                ->whereHas('roles.permissions', fn ($q) => $q->whereIn('slug', ['commercial.members.manage', 'commercial.members.assign_roles']))
                ->count();

            if ($otherCompanyAdmins === 0) {
                $companyName = $m->company->legal_name;
                throw ValidationException::withMessages([
                    'password' => "No es posible cerrar la cuenta: es el único administrador activo de la empresa '{$companyName}'. Debe designar un reemplazo primero.",
                ]);
            }
        }

        // 3. Perform soft account closure: revoke memberships, suspend platform roles, deactivate user
        DB::connection('central')->transaction(function () use ($user, $request) {
            PlatformUserRole::where('user_id', $user->id)
                ->update(['suspended_at' => now()]);

            Membership::where('user_id', $user->id)
                ->update(['status' => MembershipStatus::Revoked]);

            $user->update(['status' => UserStatus::Inactive]);

            CentralAuditLog::create([
                'user_id' => $user->id,
                'action' => 'auth.account_closed',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
