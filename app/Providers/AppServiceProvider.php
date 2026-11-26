<?php

namespace App\Providers;

use App\Http\Middleware\SetCurrentSchool;
use App\Listeners\AuditLogSubscriber;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Once;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::subscribe(AuditLogSubscriber::class);

        Livewire::addPersistentMiddleware([
            SetCurrentSchool::class,
        ]);

        $this->app->terminating(static function (): void {
            Once::flush();

            $user = auth()->user();
            SetCurrentSchool::cleanupFromMiddleware($user instanceof User ? $user : null);
        });

        View::composer(['*app.sidebar', '*app.header', 'welcome'], function (ViewContract $view): void {
            $dashboardUrl = CurrentSchool::check()
                ? route('dashboard', ['school' => CurrentSchool::idOrFail()])
                : route('schools.index');

            $view->with('dashboardUrl', $dashboardUrl);
        });

        View::composer(['*app.sidebar', '*app.header'], function (ViewContract $view): void {
            $user = auth()->user();
            $activeSchools = $user instanceof User ? $user->cachedActiveSchools() : collect();

            $view->with(
                'switchableSchools',
                $activeSchools->count() >= 2 ? $activeSchools : collect(),
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Model::shouldBeStrict(! app()->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
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
