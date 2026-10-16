<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Models\CentralAuditLog;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\CompanyInvitation;
use App\Modules\Identity\Services\CompanyMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanyInvitationController extends Controller
{
    public function __construct(
        protected CompanyMemberService $memberService
    ) {}

    /**
     * Show the invitation acceptance / account onboarding page.
     */
    public function show(Request $request, string $token): Response|RedirectResponse|JsonResponse
    {
        $tokenHash = hash('sha256', $token);
        $invitation = CompanyInvitation::where('token_hash', $tokenHash)->with('company')->first();

        if (! $invitation) {
            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Invitación no encontrada.'], 404);
            }

            return Inertia::render('auth/invitation-invalid', [
                'message' => 'El enlace de invitación no es válido o no existe.',
            ]);
        }

        if ($invitation->status !== 'pending' || $invitation->isExpired()) {
            $reason = match ($invitation->status) {
                'accepted' => 'Esta invitación ya fue utilizada previamente.',
                'cancelled' => 'Esta invitación fue cancelada por un administrador.',
                default => 'Esta invitación ha expirado.',
            };

            if ($request->wantsJson()) {
                return response()->json(['status' => 'error', 'message' => $reason], 422);
            }

            return Inertia::render('auth/invitation-invalid', [
                'message' => $reason,
            ]);
        }

        $user = User::where('email', $invitation->email)->first();
        $currentUser = $request->user();

        if ($user) {
            if (! $currentUser) {
                // Must authenticate as the corresponding user first
                return redirect()->route('login')->with('status', "Inicie sesión con {$invitation->email} para aceptar la invitación.");
            }

            if (strtolower($currentUser->email) !== strtolower($invitation->email)) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Debe iniciar sesión como {$invitation->email} para aceptar esta invitación.",
                    ], 403);
                }

                return Inertia::render('auth/invitation-invalid', [
                    'message' => "Ha iniciado sesión como {$currentUser->email}, pero esta invitación fue emitida para {$invitation->email}. Cierre sesión e ingrese con la cuenta correcta.",
                ]);
            }

            // Current logged-in user matches the invitation
            if ($request->wantsJson()) {
                return response()->json([
                    'status' => 'ok',
                    'requires_registration' => false,
                    'company_name' => $invitation->company->legal_name,
                    'email' => $invitation->email,
                ]);
            }

            return Inertia::render('auth/accept-invitation', [
                'requiresRegistration' => false,
                'companyName' => $invitation->company->legal_name,
                'email' => $invitation->email,
                'token' => $token,
            ]);
        }

        // Target user does not exist in users table: onboard via this invitation
        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'requires_registration' => true,
                'company_name' => $invitation->company->legal_name,
                'email' => $invitation->email,
            ]);
        }

        return Inertia::render('auth/accept-invitation', [
            'requiresRegistration' => true,
            'companyName' => $invitation->company->legal_name,
            'email' => $invitation->email,
            'token' => $token,
        ]);
    }

    /**
     * Accept the invitation for an existing authenticated user.
     */
    public function accept(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $tokenHash = hash('sha256', $token);
        $invitation = CompanyInvitation::where('token_hash', $tokenHash)->with('company')->firstOrFail();

        $user = $request->user();
        if (! $user || strtolower($user->email) !== strtolower($invitation->email)) {
            abort(403, 'Debe iniciar sesión con el correo electrónico al que fue emitida la invitación.');
        }

        $this->memberService->acceptInvitation($invitation, $user);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Invitación aceptada exitosamente.',
                'company_slug' => $invitation->company->slug,
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "¡Bienvenido a {$invitation->company->legal_name}!",
        ]);

        return redirect()->route('company.workspace', ['company' => $invitation->company->slug]);
    }

    /**
     * Create user account and accept invitation atomically (for new users without public registration).
     */
    public function registerAndAccept(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $tokenHash = hash('sha256', $token);
        $invitation = CompanyInvitation::where('token_hash', $tokenHash)->with('company')->firstOrFail();

        if ($invitation->status !== 'pending' || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'La invitación no es válida o ha expirado.',
            ]);
        }

        $existingUser = User::where('email', $invitation->email)->first();
        if ($existingUser) {
            return redirect()->route('login')->with('status', 'Ya posee una cuenta registrada. Por favor inicie sesión para aceptar la invitación.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = DB::connection('central')->transaction(function () use ($invitation, $validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'status' => UserStatus::Active,
                'email_verified_at' => now(), // Pre-verified because invitation was confirmed via token
            ]);

            $this->memberService->acceptInvitation($invitation, $user);

            CentralAuditLog::create([
                'user_id' => $user->id,
                'company_id' => $invitation->company_id,
                'action' => 'auth.registered_via_invitation',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => (string) $user->id,
                'new_values' => [
                    'email' => $user->email,
                    'company_id' => $invitation->company_id,
                ],
                'created_at' => now(),
            ]);

            return $user;
        });

        Auth::login($user);

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Cuenta creada y membresía activada exitosamente.',
                'company_slug' => $invitation->company->slug,
            ], 201);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Cuenta creada exitosamente. ¡Bienvenido a {$invitation->company->legal_name}!",
        ]);

        return redirect()->route('company.workspace', ['company' => $invitation->company->slug]);
    }
}
