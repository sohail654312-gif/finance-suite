<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\ModuleServiceProvider;
use App\Policies\TeamPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Horizon\Horizon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        // Register the module manager as a singleton
        $this->app->singleton(ModuleManager::class, fn ($app): ModuleManager => new ModuleManager());

        // Register the module service provider
        $this->app->register(ModuleServiceProvider::class);
    }

    public function boot(): void
    {
        if (class_exists(Horizon::class)) {
            Horizon::auth(
                static fn ($request) => Gate::check('viewHorizon', [$request->user()]));
        }

        Gate::define('viewHorizon', static fn (User $user): bool => $user->hasRole('super_admin'));
        Gate::policy(Team::class, TeamPolicy::class);
        $this->configureModels();
        $this->configureUrl();
        $this->configurePassword();
    }

    private function configureModels(): void
    {
        // Enforce mass-assignment protection ($fillable/$guarded) in every env.
        // Global unguard() was removed — it disabled that protection app-wide, so
        // a stray Model::create($request->all()) could set team_id/user_id/etc.
        // Strict mode (which makes a discarded non-fillable key throw instead of
        // silently drop) stays on only outside production, so prod degrades
        // gracefully instead of 500ing on a stale $fillable.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }

    private function configureUrl(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    private function configurePassword(): void
    {
        Password::defaults(
            static function () {
                return Password::min(12)        // NIST 800-63B: minimum 12 characters
                    ->mixedCase()      // At least one uppercase and one lowercase
                    ->numbers()        // At least one digit
                    ->symbols()        // At least one symbol (@$!%*#?&)
                    ->uncompromised(); // Check against breach database
            },
        );
    }
}
