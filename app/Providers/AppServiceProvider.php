<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Access\DTOs\CompanyContext;
use App\Modules\Access\Services\CompanyAuthorizationService;
use App\Modules\Access\Services\PlatformAuthorizationService;
use App\Modules\Audit\Listeners\AuthenticationAuditSubscriber;
use App\Modules\Companies\Models\Company;
use App\Modules\Tenancy\Services\TenantConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantConnectionManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::subscribe(AuthenticationAuditSubscriber::class);
        $this->configureAuthorizationGates();
    }

    /**
     * Configure explicit Gates for platform and commercial authorization.
     * Note: No global administrator bypass is permitted.
     */
    protected function configureAuthorizationGates(): void
    {
        Gate::define('access-platform', fn (User $user): bool => app(PlatformAuthorizationService::class)->isOperator($user));

        Gate::define('platform', fn (User $user, string $permissionSlug): bool => app(PlatformAuthorizationService::class)->can($user, $permissionSlug));

        Gate::define('company', function (User $user, string $permissionSlug, ?Company $company = null, ?bool $isMutable = null): bool {
            /** @var CompanyContext|null $context */
            $context = app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null;

            if ($context !== null && ($company === null || $company->id === $context->company->id)) {
                return app(CompanyAuthorizationService::class)->can($context, $permissionSlug, $isMutable);
            }

            if ($company !== null) {
                $resolved = app(CompanyAuthorizationService::class)->resolveContext($user, $company);
                if ($resolved !== null) {
                    return app(CompanyAuthorizationService::class)->can($resolved, $permissionSlug, $isMutable);
                }
            }

            return false;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
