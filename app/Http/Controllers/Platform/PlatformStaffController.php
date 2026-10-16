<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Models\PlatformRole;
use App\Modules\Access\Services\PlatformAuthorizationService;
use App\Modules\Access\Services\PlatformStaffService;
use App\Modules\Audit\Models\CentralAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformStaffController extends Controller
{
    public function __construct(
        protected PlatformStaffService $staffService,
        protected PlatformAuthorizationService $platformAuth
    ) {}

    /**
     * Display a listing of platform operators and available roles.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $operators = User::query()
            ->whereHas('platformRoles')
            ->with(['platformRoles.permissions'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                $isPlatformSuspended = $user->platformRoles->every(fn ($r) => $r->pivot->suspended_at !== null);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'global_status' => $user->status->value,
                    'is_platform_suspended' => $isPlatformSuspended,
                    'roles' => $user->platformRoles->map(fn ($r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'slug' => $r->slug,
                        'description' => $r->description,
                        'assigned_at' => $r->pivot->assigned_at,
                        'suspended_at' => $r->pivot->suspended_at,
                        'permissions' => $r->permissions->map(fn ($p) => [
                            'id' => $p->id,
                            'name' => $p->name,
                            'slug' => $p->slug,
                        ]),
                    ]),
                ];
            });

        $platformRoles = PlatformRole::with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'permissions' => $role->permissions->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'description' => $p->description,
                ]),
            ]);

        $canManage = $this->platformAuth->can($request->user(), 'platform.staff.manage');

        if ($request->wantsJson()) {
            return response()->json([
                'operators' => $operators,
                'platformRoles' => $platformRoles,
                'canManage' => $canManage,
            ]);
        }

        return Inertia::render('platform/staff/index', [
            'operators' => $operators,
            'platformRoles' => $platformRoles,
            'canManage' => $canManage,
        ]);
    }

    /**
     * Onboard a new or existing user as a platform operator.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:platform_roles,id'],
        ]);

        $name = $validated['name'] ?? explode('@', $validated['email'])[0];

        $operator = $this->staffService->addOrUpdateOperator(
            email: $validated['email'],
            name: $name,
            roleIds: $validated['role_ids'],
            actor: $request->user()
        );

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Operador de plataforma incorporado exitosamente.',
                'user_id' => $operator->id,
            ], 201);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Operador de plataforma incorporado exitosamente.',
        ]);

        return back();
    }

    /**
     * Update the assigned platform roles for an operator.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:platform_roles,id'],
        ]);

        $this->staffService->updateRoles(
            user: $user,
            roleIds: $validated['role_ids'],
            actor: $request->user()
        );

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Roles de plataforma actualizados.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Roles de plataforma actualizados exitosamente.',
        ]);

        return back();
    }

    /**
     * Toggle suspension of platform access for an operator.
     */
    public function toggleSuspension(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $isCurrentlySuspended = $user->platformRoles()
            ->wherePivotNull('suspended_at')
            ->doesntExist();

        if ($isCurrentlySuspended) {
            $this->staffService->reactivateAccess($user, $request->user());
            $message = 'Acceso a plataforma reactivado.';
        } else {
            $this->staffService->suspendAccess($user, $request->user());
            $message = 'Acceso a plataforma suspendido.';
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => $message,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Revoke platform access entirely from an operator without deleting their user or company memberships.
     */
    public function destroy(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->staffService->revokeAccess($user, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Acceso a plataforma revocado completamente.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Acceso de plataforma revocado. Las membresías empresariales se conservan.',
        ]);

        return back();
    }

    /**
     * Display access audit trail for platform events.
     */
    public function audit(Request $request): Response|JsonResponse
    {
        $logs = CentralAuditLog::query()
            ->where(function ($q) {
                $q->where('action', 'like', 'platform.%')
                    ->orWhere('action', 'like', 'auth.%');
            })
            ->with('user')
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'ip_address' => $log->ip_address,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        if ($request->wantsJson()) {
            return response()->json($logs);
        }

        return Inertia::render('platform/audit/index', [
            'logs' => $logs,
        ]);
    }
}
