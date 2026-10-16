<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Models\Permission;
use App\Modules\Access\Services\CompanyAuthorizationService;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\CompanyInvitation;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Notifications\CompanyInvitationNotification;
use App\Modules\Licensing\Enums\LicenseValidityStatus;
use App\Modules\Licensing\Services\LicenseResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class CompanyMemberService
{
    public function __construct(
        protected LicenseResolverService $licenseResolver,
        protected CompanyAuthorizationService $companyAuth
    ) {}

    /**
     * Enforce the license max users limit with pessimistic row lock on company.
     * Policy: Total occupied seats = Active memberships (+ pending unexpired invitations when creating/sending invitations).
     *
     * @throws ValidationException
     */
    public function checkUserLimit(Company $company, bool $includePending = true): void
    {
        Company::where('id', $company->id)->lockForUpdate()->first();

        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        $license = $licenseResult->license;

        if (! $license || $license->max_users <= 0) {
            return;
        }

        $activeMembers = Membership::where('company_id', $company->id)
            ->where('status', MembershipStatus::Active)
            ->count();

        $occupied = $activeMembers;

        if ($includePending) {
            $pendingInvites = CompanyInvitation::where('company_id', $company->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->count();

            $occupied += $pendingInvites;
        }

        if ($occupied >= $license->max_users) {
            throw ValidationException::withMessages([
                'license' => "Límite de usuarios de la licencia alcanzado ({$license->max_users} usuarios permitidos). No es posible incorporar más miembros.",
            ]);
        }
    }

    /**
     * Ensure the given membership is not the sole remaining active administrator of the company.
     *
     * @throws ValidationException
     */
    public function ensureNotLastCompanyAdmin(Company $company, Membership $membership): void
    {
        // Check if this membership has member management capacity
        $hasAdminCapacity = $membership->roles()
            ->whereHas('permissions', fn ($q) => $q->whereIn('slug', ['commercial.members.manage', 'commercial.members.assign_roles']))
            ->exists();

        if (! $hasAdminCapacity) {
            return;
        }

        // Lock company record to serialize admin mutations
        Company::where('id', $company->id)->lockForUpdate()->first();

        // Count other active memberships with member management capacity
        $otherActiveAdminsCount = Membership::query()
            ->where('company_id', $company->id)
            ->where('id', '!=', $membership->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('roles', function ($q) {
                $q->whereHas('permissions', fn ($pq) => $pq->whereIn('slug', ['commercial.members.manage', 'commercial.members.assign_roles']));
            })
            ->count();

        if ($otherActiveAdminsCount === 0) {
            throw ValidationException::withMessages([
                'member' => 'No es posible revocar, suspender o degradar al último administrador activo de la empresa.',
                'membership' => 'No es posible revocar, suspender o degradar al último administrador activo de la empresa.',
            ]);
        }
    }

    /**
     * Create and issue an invitation for the company.
     *
     * @param  list<int>  $roleIds
     * @return array{invitation: CompanyInvitation, plain_token: string}
     */
    public function createInvitation(Company $company, string $email, array $roleIds, User $actor): array
    {
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible emitir nuevas invitaciones mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        // Check if user is already an active member of this company
        $existingMembership = Membership::where('company_id', $company->id)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->where('status', MembershipStatus::Active)
            ->exists();

        if ($existingMembership) {
            throw ValidationException::withMessages([
                'email' => 'El usuario ya cuenta con una membresía activa en esta empresa.',
            ]);
        }

        // Validate that roles belong strictly to this company
        $validRoleIds = CompanyRole::where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->all();

        // A non-owner cannot invite someone with the company_owner role
        $ownerRole = CompanyRole::where('company_id', $company->id)->where('slug', 'company_owner')->first();
        if ($ownerRole && in_array($ownerRole->id, $validRoleIds, true)) {
            $actorMembership = Membership::where('company_id', $company->id)
                ->where('user_id', $actor->id)
                ->where('status', MembershipStatus::Active)
                ->first();

            if (! $actorMembership || ! $actorMembership->is_owner) {
                throw ValidationException::withMessages([
                    'role_ids' => 'Solo el propietario titular puede emitir invitaciones con el rol de propietario.',
                ]);
            }
        }

        // Enforce privilege delegation boundary: actor cannot delegate permissions they do not possess
        $actorContext = $this->companyAuth->resolveContext($actor, $company);
        $actorPermissions = $actorContext ? $actorContext->effectivePermissionSlugs : [];
        $rolesPermissions = Permission::whereHas('companyRoles', fn ($q) => $q->whereIn('id', $validRoleIds))->pluck('slug')->all();
        $unauthorized = array_diff($rolesPermissions, $actorPermissions);

        if (! empty($unauthorized)) {
            throw ValidationException::withMessages([
                'role_ids' => 'No tiene autorización para asignar roles que contengan permisos superiores a su capacidad de delegación.',
            ]);
        }

        return DB::connection('central')->transaction(function () use ($company, $email, $validRoleIds, $actor) {
            // Enforce license user limit under lock
            $this->checkUserLimit($company, includePending: true);

            // Invalidate any previous pending invitation for this email in this company
            CompanyInvitation::where('company_id', $company->id)
                ->where('email', $email)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

            [$plainToken, $tokenHash] = CompanyInvitation::createTokenPair();

            $invitation = CompanyInvitation::create([
                'company_id' => $company->id,
                'email' => $email,
                'token_hash' => $tokenHash,
                'role_ids' => $validRoleIds,
                'invited_by_user_id' => $actor->id,
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.invitation.created',
                'auditable_type' => $invitation->getMorphClass(),
                'auditable_id' => (string) $invitation->id,
                'new_values' => [
                    'email' => $email,
                    'role_ids' => $validRoleIds,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            // Dispatch notification email
            Notification::route('mail', $email)
                ->notify(new CompanyInvitationNotification($company, $plainToken, $invitation->expires_at));

            return [
                'invitation' => $invitation,
                'plain_token' => $plainToken,
            ];
        });
    }

    /**
     * Resend an invitation with a fresh expiration and token, invalidating the previous link.
     *
     * @return array{invitation: CompanyInvitation, plain_token: string}
     */
    public function resendInvitation(Company $company, int $invitationId, User $actor): array
    {
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible reenviar invitaciones mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        $invitation = CompanyInvitation::where('company_id', $company->id)
            ->findOrFail($invitationId);

        if ($invitation->status === 'accepted') {
            throw ValidationException::withMessages([
                'invitation' => 'La invitación ya fue aceptada previamente.',
            ]);
        }

        return DB::connection('central')->transaction(function () use ($invitation, $actor, $company) {
            $this->checkUserLimit($company, includePending: true);

            [$plainToken, $tokenHash] = CompanyInvitation::createTokenPair();

            $invitation->update([
                'token_hash' => $tokenHash,
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'cancelled_at' => null,
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.invitation.resent',
                'auditable_type' => $invitation->getMorphClass(),
                'auditable_id' => (string) $invitation->id,
                'new_values' => [
                    'email' => $invitation->email,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            // Dispatch notification email with new link
            Notification::route('mail', $invitation->email)
                ->notify(new CompanyInvitationNotification($company, $plainToken, $invitation->expires_at));

            return [
                'invitation' => $invitation,
                'plain_token' => $plainToken,
            ];
        });
    }

    /**
     * Cancel an existing invitation.
     */
    public function cancelInvitation(Company $company, int $invitationId, User $actor): void
    {
        $invitation = CompanyInvitation::where('company_id', $company->id)
            ->findOrFail($invitationId);

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Solo se pueden cancelar invitaciones en estado pendiente.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($invitation, $actor, $company) {
            $invitation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.invitation.cancelled',
                'auditable_type' => $invitation->getMorphClass(),
                'auditable_id' => (string) $invitation->id,
                'new_values' => [
                    'email' => $invitation->email,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Accept an invitation and activate the user's company membership.
     */
    public function acceptInvitation(CompanyInvitation $invitation, User $user): Membership
    {
        if (strtolower($invitation->email) !== strtolower($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => 'La invitación fue emitida para un correo electrónico diferente. Inicie sesión con la cuenta correspondiente.',
            ]);
        }

        $company = $invitation->company;

        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'invitation' => 'La licencia de la empresa se encuentra vencida. No es posible activar la membresía.',
            ]);
        }

        return DB::connection('central')->transaction(function () use ($invitation, $user, $company) {
            // Lock invitation for update to strictly prevent race conditions and duplicate acceptances
            /** @var CompanyInvitation $lockedInvitation */
            $lockedInvitation = CompanyInvitation::where('id', $invitation->id)->lockForUpdate()->firstOrFail();

            if ($lockedInvitation->status !== 'pending' || $lockedInvitation->isExpired()) {
                throw ValidationException::withMessages([
                    'invitation' => 'La invitación ya fue aceptada, cancelada o ha expirado.',
                ]);
            }

            $this->checkUserLimit($company, includePending: false);

            $membership = Membership::where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $membership) {
                $membership = Membership::create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);
            } else {
                $membership->update([
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);
            }

            if (! empty($lockedInvitation->role_ids)) {
                $membership->syncRoles($lockedInvitation->role_ids);
            }

            $lockedInvitation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            CentralAuditLog::create([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'action' => 'company.invitation.accepted',
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => (string) $membership->id,
                'new_values' => [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'company_id' => $company->id,
                ],
                'created_at' => now(),
            ]);

            return $membership;
        });
    }

    /**
     * Suspend a member's access in this company without affecting their global account or other companies.
     */
    public function suspendMembership(Company $company, Membership $membership, User $actor): void
    {
        if ($membership->is_owner) {
            throw ValidationException::withMessages([
                'member' => 'No es posible suspender al propietario titular de la empresa. Debe transferir la titularidad primero.',
                'membership' => 'No es posible suspender al propietario titular de la empresa. Debe transferir la titularidad primero.',
            ]);
        }

        $this->ensureNotLastCompanyAdmin($company, $membership);

        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages([
                'member' => 'No está permitido suspender su propia membresía.',
                'membership' => 'No está permitido suspender su propia membresía.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($membership, $actor, $company) {
            $membership->update([
                'status' => MembershipStatus::Suspended,
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.membership.suspended',
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => (string) $membership->id,
                'new_values' => [
                    'target_user_id' => $membership->user_id,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Reactivate a suspended member in this company.
     */
    public function reactivateMembership(Company $company, Membership $membership, User $actor): void
    {
        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages([
                'membership' => 'No está permitido reactivar su propia membresía.',
            ]);
        }

        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            throw ValidationException::withMessages([
                'license' => 'No es posible reactivar membresías mientras la licencia de la empresa se encuentre vencida.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($membership, $actor, $company) {
            $this->checkUserLimit($company, includePending: false);

            $membership->update([
                'status' => MembershipStatus::Active,
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.membership.reactivated',
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => (string) $membership->id,
                'new_values' => [
                    'target_user_id' => $membership->user_id,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Revoke a membership from this company without deleting the user account or memberships in other companies.
     */
    public function revokeMembership(Company $company, Membership $membership, User $actor): void
    {
        if ($membership->is_owner) {
            throw ValidationException::withMessages([
                'member' => 'No es posible revocar la membresía del propietario titular de la empresa. Debe transferir la titularidad primero.',
                'membership' => 'No es posible revocar la membresía del propietario titular de la empresa. Debe transferir la titularidad primero.',
            ]);
        }

        $this->ensureNotLastCompanyAdmin($company, $membership);

        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages([
                'member' => 'No está permitido revocar su propia membresía.',
                'membership' => 'No está permitido revocar su propia membresía.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($membership, $actor, $company) {
            $membership->update([
                'status' => MembershipStatus::Revoked,
            ]);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.membership.revoked',
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => (string) $membership->id,
                'new_values' => [
                    'target_user_id' => $membership->user_id,
                ],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Assign company roles to a member, strictly scoped to this company.
     *
     * @param  list<int>  $roleIds
     */
    public function assignRoles(Company $company, Membership $membership, array $roleIds, User $actor): void
    {
        // Check if removing admin capacity from the last admin
        $targetRolesHaveAdmin = CompanyRole::where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($q) => $q->where('slug', 'commercial.members.manage'))
            ->exists();

        if (! $targetRolesHaveAdmin) {
            $this->ensureNotLastCompanyAdmin($company, $membership);
        }

        if ($membership->user_id === $actor->id) {
            throw ValidationException::withMessages([
                'member' => 'No está permitido modificar los roles de su propia membresía.',
                'membership' => 'No está permitido modificar los roles de su propia membresía.',
            ]);
        }

        // Filter roleIds to ensure they belong exclusively to this company
        $validRoleIds = CompanyRole::where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->pluck('id')
            ->all();

        // Expired license check: privilege expansion forbidden
        $licenseResult = $this->licenseResolver->resolveEffectiveLicense($company);
        if ($licenseResult->validityStatus === LicenseValidityStatus::Expired) {
            $currentRoleIds = $membership->roles()->pluck('id')->all();
            $addedRoleIds = array_diff($validRoleIds, $currentRoleIds);
            if (! empty($addedRoleIds)) {
                throw ValidationException::withMessages([
                    'license' => 'No es posible ampliar privilegios ni asignar nuevos roles mientras la licencia de la empresa se encuentre vencida.',
                ]);
            }
        }

        // Check owner modification constraints
        $actorMembership = Membership::where('company_id', $company->id)
            ->where('user_id', $actor->id)
            ->where('status', MembershipStatus::Active)
            ->first();

        if ($membership->is_owner && $actor->id !== $membership->user_id) {
            throw ValidationException::withMessages([
                'member' => 'Solo el propietario titular puede modificar sus propios roles.',
                'membership' => 'Solo el propietario titular puede modificar sus propios roles.',
            ]);
        }

        $ownerRole = CompanyRole::where('company_id', $company->id)->where('slug', 'company_owner')->first();
        if ($ownerRole) {
            $actorIsOwner = $actorMembership && $actorMembership->is_owner;
            if (! $actorIsOwner && in_array($ownerRole->id, $validRoleIds, true)) {
                throw ValidationException::withMessages([
                    'role_ids' => 'Solo el propietario titular puede asignar el rol de propietario.',
                ]);
            }
            if ($membership->is_owner && ! in_array($ownerRole->id, $validRoleIds, true)) {
                throw ValidationException::withMessages([
                    'role_ids' => 'No es posible retirar el rol de propietario del titular activo sin una transferencia formal.',
                ]);
            }
        }

        // Check if removing admin capacity from the last admin
        $targetRolesHaveAdmin = CompanyRole::where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($q) => $q->whereIn('slug', ['commercial.members.manage', 'commercial.members.assign_roles']))
            ->exists();

        if (! $targetRolesHaveAdmin) {
            $this->ensureNotLastCompanyAdmin($company, $membership);
        }

        // Enforce privilege delegation boundary: actor cannot delegate permissions they do not possess
        $actorContext = $this->companyAuth->resolveContext($actor, $company);
        $actorPermissions = $actorContext ? $actorContext->effectivePermissionSlugs : [];
        $rolesPermissions = Permission::whereHas('companyRoles', fn ($q) => $q->whereIn('id', $validRoleIds))->pluck('slug')->all();
        $unauthorized = array_diff($rolesPermissions, $actorPermissions);

        if (! empty($unauthorized)) {
            throw ValidationException::withMessages([
                'role_ids' => 'No tiene autorización para asignar roles que contengan permisos superiores a su capacidad de delegación.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($membership, $validRoleIds, $actor, $company) {
            $membership->syncRoles($validRoleIds);

            CentralAuditLog::create([
                'user_id' => $actor->id,
                'company_id' => $company->id,
                'action' => 'company.membership.roles_updated',
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => (string) $membership->id,
                'new_values' => [
                    'target_user_id' => $membership->user_id,
                    'role_ids' => $validRoleIds,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
