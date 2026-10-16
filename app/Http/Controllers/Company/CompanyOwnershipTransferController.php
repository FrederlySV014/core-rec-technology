<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Identity\Models\CompanyOwnershipTransfer;
use App\Modules\Identity\Services\CompanyOwnershipTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyOwnershipTransferController extends Controller
{
    /**
     * Initiate a company ownership transfer request to another verified member.
     */
    public function initiate(
        Request $request,
        Company $company,
        CompanyOwnershipTransferService $transferService
    ): RedirectResponse {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:central.users,id'],
        ]);

        /** @var User $targetUser */
        $targetUser = User::findOrFail($validated['target_user_id']);

        $transferService->initiateTransfer($company, $request->user(), $targetUser);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Solicitud de transferencia iniciada exitosamente. Se ha notificado por correo electrónico al destinatario.',
        ]);

        return back();
    }

    /**
     * Cancel any active ownership transfer.
     */
    public function cancel(
        Request $request,
        Company $company,
        CompanyOwnershipTransferService $transferService
    ): RedirectResponse {
        $transferService->cancelTransfer($company, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Solicitud de transferencia de titularidad cancelada.',
        ]);

        return back();
    }

    /**
     * Display the transfer acceptance review page.
     */
    public function show(Request $request, Company $company, string $token): Response|RedirectResponse
    {
        $tokenHash = hash('sha256', $token);

        /** @var CompanyOwnershipTransfer|null $transfer */
        $transfer = CompanyOwnershipTransfer::where('company_id', $company->id)
            ->where('token_hash', $tokenHash)
            ->with(['currentOwner', 'targetUser'])
            ->first();

        if ($transfer === null || ! $transfer->isPending()) {
            return Inertia::render('company/ownership-transfer/invalid', [
                'company' => [
                    'legal_name' => $company->legal_name,
                    'slug' => $company->slug,
                ],
                'message' => 'La solicitud de transferencia de titularidad no es válida, ha vencido o fue cancelada por el propietario actual.',
            ]);
        }

        $user = $request->user();
        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->id !== $transfer->target_user_id) {
            abort(403, 'Esta solicitud de transferencia de titularidad no corresponde a su cuenta de usuario.');
        }

        return Inertia::render('company/ownership-transfer/show', [
            'company' => [
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'slug' => $company->slug,
            ],
            'token' => $token,
            'currentOwner' => [
                'name' => $transfer->currentOwner->name,
                'email' => $transfer->currentOwner->email,
            ],
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication() && $user->two_factor_confirmed_at !== null,
                'two_factor_cleared' => (bool) $request->session()->get('auth.2fa_passed', false),
            ],
            'expiresAt' => $transfer->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Accept the ownership transfer and atomically assume ownership.
     */
    public function accept(
        Request $request,
        Company $company,
        string $token,
        CompanyOwnershipTransferService $transferService
    ): RedirectResponse {
        $transferService->acceptTransfer($company, $token, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Ha asumido formalmente la titularidad exclusiva de la empresa.',
        ]);

        return redirect()->route('company.workspace', ['company' => $company->slug]);
    }
}
