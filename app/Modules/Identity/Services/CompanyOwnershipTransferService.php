<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Services\PrivilegeService;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\CompanyOwnershipTransfer;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Notifications\CompanyOwnershipTransferNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyOwnershipTransferService
{
    public function __construct(
        protected PrivilegeService $privilegeService
    ) {}

    /**
     * Initiate a new formal ownership transfer request.
     */
    public function initiateTransfer(Company $company, User $currentOwner, User $targetUser): CompanyOwnershipTransfer
    {
        // 1. Current user must be the active owner of this company
        $currentMembership = Membership::where('company_id', $company->id)
            ->where('user_id', $currentOwner->id)
            ->where('status', MembershipStatus::Active)
            ->where('is_owner', true)
            ->first();

        if ($currentMembership === null) {
            throw ValidationException::withMessages([
                'owner' => 'Solo el propietario titular activo puede iniciar una transferencia de propiedad.',
            ]);
        }

        // 2. Current owner must have 2FA confirmed and cleared
        if (! $this->privilegeService->hasClearedTwoFactor($currentOwner)) {
            throw ValidationException::withMessages([
                'two_factor' => 'Debe haber superado el desafío de autenticación de dos factores (2FA) en esta sesión para iniciar una transferencia de titularidad.',
            ]);
        }

        // 3. Target user cannot be the current owner
        if ($targetUser->id === $currentOwner->id) {
            throw ValidationException::withMessages([
                'target_user_id' => 'El destinatario de la titularidad debe ser un miembro distinto al propietario actual.',
            ]);
        }

        // 4. Target user must be an active, email-verified member of the company
        $targetMembership = Membership::where('company_id', $company->id)
            ->where('user_id', $targetUser->id)
            ->where('status', MembershipStatus::Active)
            ->first();

        if ($targetMembership === null) {
            throw ValidationException::withMessages([
                'target_user_id' => 'El destinatario debe contar con una membresía activa en la empresa.',
            ]);
        }

        if (! $targetUser->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'target_user_id' => 'El destinatario debe tener su correo electrónico verificado antes de recibir la titularidad.',
            ]);
        }

        // 5. Target user must have 2FA enabled and confirmed
        if (! $targetUser->hasEnabledTwoFactorAuthentication() || $targetUser->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages([
                'target_user_id' => 'El destinatario debe tener la autenticación de dos factores (2FA) activada y confirmada en su cuenta.',
            ]);
        }

        return DB::connection('central')->transaction(function () use ($company, $currentOwner, $targetUser) {
            // Cancel any existing pending transfers for this company
            CompanyOwnershipTransfer::where('company_id', $company->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

            $plainToken = Str::random(40);
            $tokenHash = hash('sha256', $plainToken);
            $expiresAt = now()->addDays(2); // 48-hour valid window

            $transfer = CompanyOwnershipTransfer::create([
                'company_id' => $company->id,
                'current_owner_id' => $currentOwner->id,
                'target_user_id' => $targetUser->id,
                'status' => 'pending',
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
            ]);

            CentralAuditLog::create([
                'user_id' => $currentOwner->id,
                'company_id' => $company->id,
                'action' => 'company.ownership_transfer_initiated',
                'auditable_type' => $transfer->getMorphClass(),
                'auditable_id' => (string) $transfer->id,
                'new_values' => [
                    'current_owner_id' => $currentOwner->id,
                    'target_user_id' => $targetUser->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                'created_at' => now(),
            ]);

            // Notify recipient securely via email without logging plaintext token
            $targetUser->notify(new CompanyOwnershipTransferNotification($company, $plainToken, $expiresAt));

            return $transfer;
        });
    }

    /**
     * Accept a pending ownership transfer request.
     */
    public function acceptTransfer(Company $company, string $token, User $targetUser): void
    {
        $tokenHash = hash('sha256', $token);

        DB::connection('central')->transaction(function () use ($company, $tokenHash, $targetUser) {
            /** @var CompanyOwnershipTransfer|null $transfer */
            $transfer = CompanyOwnershipTransfer::where('company_id', $company->id)
                ->where('token_hash', $tokenHash)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($transfer === null || $transfer->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'transfer' => 'La solicitud de transferencia de titularidad no es válida o ha vencido.',
                ]);
            }

            if ($transfer->target_user_id !== $targetUser->id) {
                throw ValidationException::withMessages([
                    'transfer' => 'Esta solicitud de transferencia no está dirigida a su usuario.',
                ]);
            }

            // Target user must have 2FA cleared in the current session
            if (! $this->privilegeService->hasClearedTwoFactor($targetUser)) {
                throw ValidationException::withMessages([
                    'two_factor' => 'Debe haber superado el desafío de autenticación de dos factores (2FA) en esta sesión para aceptar la titularidad.',
                ]);
            }

            // Lock company to serialize membership changes
            Company::where('id', $company->id)->lockForUpdate()->first();

            // Fetch and validate current owner membership
            /** @var Membership|null $currentOwnerMembership */
            $currentOwnerMembership = Membership::where('company_id', $company->id)
                ->where('user_id', $transfer->current_owner_id)
                ->where('status', MembershipStatus::Active)
                ->where('is_owner', true)
                ->lockForUpdate()
                ->first();

            if ($currentOwnerMembership === null) {
                throw ValidationException::withMessages([
                    'owner' => 'El propietario original ya no se encuentra disponible como titular activo de la empresa.',
                ]);
            }

            // Fetch and validate target user membership
            /** @var Membership|null $targetMembership */
            $targetMembership = Membership::where('company_id', $company->id)
                ->where('user_id', $targetUser->id)
                ->where('status', MembershipStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($targetMembership === null) {
                throw ValidationException::withMessages([
                    'target_user' => 'Su membresía en la empresa ya no se encuentra activa.',
                ]);
            }

            // Resolve company system roles
            $ownerRole = CompanyRole::where('company_id', $company->id)->where('slug', 'company_owner')->first();
            $adminRole = CompanyRole::where('company_id', $company->id)->where('slug', 'company_admin')->first();

            // 1. Demote old owner to administrator
            $currentOwnerMembership->update(['is_owner' => false]);
            if ($adminRole) {
                $currentOwnerMembership->assignRole($adminRole);
            }
            if ($ownerRole) {
                $currentOwnerMembership->roles()->detach($ownerRole->id);
            }

            // 2. Promote target member to owner
            $targetMembership->update(['is_owner' => true]);
            if ($ownerRole) {
                $targetMembership->assignRole($ownerRole);
            }

            // 3. Mark transfer accepted
            $transfer->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            CentralAuditLog::create([
                'user_id' => $targetUser->id,
                'company_id' => $company->id,
                'action' => 'company.ownership_transferred',
                'auditable_type' => $company->getMorphClass(),
                'auditable_id' => (string) $company->id,
                'old_values' => ['owner_user_id' => $currentOwnerMembership->user_id],
                'new_values' => ['owner_user_id' => $targetUser->id, 'transfer_id' => $transfer->id],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Cancel an active transfer request by the current owner.
     */
    public function cancelTransfer(Company $company, User $currentOwner): void
    {
        $currentMembership = Membership::where('company_id', $company->id)
            ->where('user_id', $currentOwner->id)
            ->where('status', MembershipStatus::Active)
            ->where('is_owner', true)
            ->first();

        if ($currentMembership === null) {
            throw ValidationException::withMessages([
                'owner' => 'Solo el propietario titular puede cancelar una transferencia de propiedad.',
            ]);
        }

        DB::connection('central')->transaction(function () use ($company, $currentOwner) {
            $updated = CompanyOwnershipTransfer::where('company_id', $company->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

            if ($updated > 0) {
                CentralAuditLog::create([
                    'user_id' => $currentOwner->id,
                    'company_id' => $company->id,
                    'action' => 'company.ownership_transfer_cancelled',
                    'auditable_type' => $company->getMorphClass(),
                    'auditable_id' => (string) $company->id,
                    'created_at' => now(),
                ]);
            }
        });
    }
}
