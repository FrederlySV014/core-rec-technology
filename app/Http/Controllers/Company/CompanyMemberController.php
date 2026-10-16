<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Models\CompanyRole;
use App\Modules\Access\Services\CompanyRoleService;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Models\CompanyInvitation;
use App\Modules\Identity\Models\CompanyOwnershipTransfer;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Services\CompanyMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyMemberController extends Controller
{
    public function __construct(
        protected CompanyMemberService $memberService,
        protected CompanyRoleService $roleService
    ) {}

    /**
     * Display a listing of members and pending invitations for the company.
     */
    public function index(Request $request): Response|JsonResponse
    {
        /** @var CompanyContext $context */
        $context = $request->attributes->get('company_context');
        $company = $context->company;

        $members = Membership::where('company_id', $company->id)
            ->with(['user', 'roles.permissions'])
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'is_owner' => (bool) $m->is_owner,
                'user' => [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'email_verified' => $m->user->hasVerifiedEmail(),
                    'two_factor_enabled' => $m->user->hasEnabledTwoFactorAuthentication() && $m->user->two_factor_confirmed_at !== null,
                ],
                'status' => $m->status->value,
                'joined_at' => $m->created_at?->toIso8601String(),
                'roles' => $m->roles->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'is_system' => $r->is_system,
                ]),
            ]);

        $pendingTransfer = CompanyOwnershipTransfer::where('company_id', $company->id)
            ->where('status', 'pending')
            ->with(['currentOwner', 'targetUser'])
            ->first();

        $pendingTransferData = $pendingTransfer ? [
            'id' => $pendingTransfer->id,
            'target_user' => [
                'id' => $pendingTransfer->targetUser->id,
                'name' => $pendingTransfer->targetUser->name,
                'email' => $pendingTransfer->targetUser->email,
            ],
            'current_owner' => [
                'id' => $pendingTransfer->currentOwner->id,
                'name' => $pendingTransfer->currentOwner->name,
            ],
            'expires_at' => $pendingTransfer->expires_at->toIso8601String(),
        ] : null;

        $invitations = CompanyInvitation::where('company_id', $company->id)
            ->whereIn('status', ['pending', 'expired', 'cancelled'])
            ->with('invitedBy')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompanyInvitation $inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'status' => $inv->isExpired() && $inv->status === 'pending' ? 'expired' : $inv->status,
                'expires_at' => $inv->expires_at->toIso8601String(),
                'role_ids' => $inv->role_ids,
                'invited_by' => $inv->invitedBy?->name,
            ]);

        $availableRoles = CompanyRole::where('company_id', $company->id)
            ->with('permissions')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'is_system' => $r->is_system,
                'description' => $r->description,
            ]);

        $isCurrentUserOwner = (bool) $context->membership->is_owner;

        if ($request->wantsJson()) {
            return response()->json([
                'members' => $members,
                'invitations' => $invitations,
                'availableRoles' => $availableRoles,
                'pendingTransfer' => $pendingTransferData,
                'isCurrentUserOwner' => $isCurrentUserOwner,
            ]);
        }

        return Inertia::render('company/members/index', [
            'company' => [
                'name' => $company->legal_name,
                'slug' => $company->slug,
            ],
            'members' => $members,
            'invitations' => $invitations,
            'availableRoles' => $availableRoles,
            'pendingTransfer' => $pendingTransferData,
            'isCurrentUserOwner' => $isCurrentUserOwner,
        ]);
    }

    /**
     * Invite a user to the company by email.
     */
    public function invite(Request $request): RedirectResponse|JsonResponse
    {
        /** @var CompanyContext $context */
        $context = $request->attributes->get('company_context');
        $company = $context->company;

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:company_roles,id'],
        ]);

        $result = $this->memberService->createInvitation(
            company: $company,
            email: $validated['email'],
            roleIds: $validated['role_ids'],
            actor: $request->user()
        );

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Invitación enviada exitosamente por correo electrónico.',
                'invitation_id' => $result['invitation']->id,
            ], 201);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Invitación enviada por correo electrónico a {$validated['email']}.",
        ]);

        return back();
    }

    /**
     * Resend an invitation.
     */
    public function resend(Request $request, Company $company, int $invitationId): RedirectResponse|JsonResponse
    {
        $result = $this->memberService->resendInvitation($company, $invitationId, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Invitación reenviada exitosamente por correo electrónico.',
                'invitation_id' => $result['invitation']->id,
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Invitación reenviada por correo electrónico con enlace renovado.',
        ]);

        return back();
    }

    /**
     * Cancel a pending invitation.
     */
    public function cancel(Request $request, Company $company, int $invitationId): RedirectResponse|JsonResponse
    {
        $this->memberService->cancelInvitation($company, $invitationId, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Invitación cancelada.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitación cancelada.']);

        return back();
    }

    /**
     * Suspend or reactivate a member in the company.
     */
    public function toggleSuspension(Request $request, Company $company, Membership $membership): RedirectResponse|JsonResponse
    {
        if ($membership->company_id !== $company->id) {
            abort(404);
        }

        /** @var CompanyContext $context */
        $context = $request->attributes->get('company_context');

        if ($membership->status === MembershipStatus::Suspended) {
            if (! $context->hasPermission('commercial.members.reactivate')) {
                abort(403, 'No tiene autorización para reactivar miembros en la empresa.');
            }
            $this->memberService->reactivateMembership($company, $membership, $request->user());
            $message = 'Membresía reactivada exitosamente.';
        } else {
            if (! $context->hasPermission('commercial.members.suspend')) {
                abort(403, 'No tiene autorización para suspender miembros en la empresa.');
            }
            $this->memberService->suspendMembership($company, $membership, $request->user());
            $message = 'Membresía suspendida exitosamente.';
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok', 'message' => $message]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Revoke a membership entirely from the company.
     */
    public function destroy(Request $request, Company $company, Membership $membership): RedirectResponse|JsonResponse
    {
        if ($membership->company_id !== $company->id) {
            abort(404);
        }

        $this->memberService->revokeMembership($company, $membership, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Membresía revocada exitosamente.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Membresía revocada exitosamente.']);

        return back();
    }

    /**
     * Update roles assigned to a member.
     */
    public function updateRoles(Request $request, Company $company, Membership $membership): RedirectResponse|JsonResponse
    {
        if ($membership->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:company_roles,id'],
        ]);

        $this->memberService->assignRoles($company, $membership, $validated['role_ids'], $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Roles de membresía actualizados.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Roles de membresía actualizados exitosamente.']);

        return back();
    }
}
