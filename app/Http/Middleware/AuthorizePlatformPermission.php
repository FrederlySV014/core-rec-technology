<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Access\Services\PlatformAuthorizationService;
use App\Modules\Identity\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePlatformPermission
{
    public function __construct(
        protected PlatformAuthorizationService $platformAuth
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permissionSlug): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            abort(403, 'Acceso denegado: usuario no autenticado o inactivo.');
        }

        if (! $this->platformAuth->can($user, $permissionSlug)) {
            abort(403, 'Acceso denegado: no cuenta con el permiso administrativo requerido.');
        }

        return $next($request);
    }
}
