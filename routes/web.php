<?php

use App\Http\Middleware\SetCurrentSchool;
use App\Livewire\Schools\Index as SchoolsIndex;
use App\Livewire\Schools\Register as SchoolsRegister;
use App\Livewire\Setup\AcademicYears as SetupAcademicYears;
use App\Livewire\Setup\Grades as SetupGrades;
use App\Models\AcademicYear;
use App\Models\Grade;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('register-school', SchoolsRegister::class)
    ->middleware('guest')
    ->name('schools.register');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route('schools.index'));

    Route::livewire('schools', SchoolsIndex::class)->name('schools.index');
});

Route::prefix('{school}')
    ->whereUlid('school')
    ->middleware(['auth', 'verified', SetCurrentSchool::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');

        Route::livewire('setup/academic-years', SetupAcademicYears::class)
            ->can('viewAny', AcademicYear::class)
            ->name('setup.academic-years');

        Route::livewire('setup/grades', SetupGrades::class)
            ->can('viewAny', Grade::class)
            ->name('setup.grades');
    });

require __DIR__.'/settings.php';
