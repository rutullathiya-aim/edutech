<?php

namespace App\Providers;

use App\Http\Middleware\SetCurrentSchool;
use App\Listeners\AuditLogSubscriber;
use App\Tenant\CurrentSchool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
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

        View::composer(['*app.sidebar', '*app.header', 'welcome'], function ($view): void {
            $dashboardUrl = CurrentSchool::check()
                ? route('dashboard', ['school' => CurrentSchool::idOrFail()])
                : (auth()->user()?->defaultSchool()
                    ? route('dashboard', ['school' => auth()->user()->defaultSchool()->id])
                    : url('/'));

            $view->with('dashboardUrl', $dashboardUrl);
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
