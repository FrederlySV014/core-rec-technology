<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $request->routeIs('logout')) {
            if ($user->status !== UserStatus::Active) {
                Auth::guard('web')->logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Esta cuenta se encuentra inactiva o suspendida.',
                    ], 403);
                }

                return redirect()->route('login')->withErrors([
                    'email' => 'Su cuenta ha sido desactivada o suspendida.',
                ]);
            }
        }

        if ($request->hasSession() && $request->session()->has('login.id')) {
            $challengedId = $request->session()->get('login.id');
            $challengedUser = User::find($challengedId);

            if ($challengedUser instanceof User && $challengedUser->status !== UserStatus::Active) {
                $request->session()->forget(['login.id', 'login.remember']);
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Esta cuenta se encuentra inactiva o suspendida.',
                    ], 403);
                }

                return redirect()->route('login')->withErrors([
                    'email' => 'Su cuenta ha sido desactivada o suspendida.',
                ]);
            }
        }

        return $next($request);
    }
}
