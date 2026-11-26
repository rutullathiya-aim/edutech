<?php

use App\Http\Controllers\ShowInvitationController;
use App\Http\Middleware\SetCurrentSchool;
use App\Livewire\Dashboard;
use App\Livewire\Invitations\Accept as AcceptInvitationPage;
use App\Livewire\Schools\Index as SchoolsIndex;
use App\Livewire\Schools\Register as SchoolsRegister;
use App\Livewire\Setup\AcademicYears as SetupAcademicYears;
use App\Livewire\Setup\Grades as SetupGrades;
use App\Livewire\Setup\Sections as SetupSections;
use App\Livewire\Setup\Subjects as SetupSubjects;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::livewire('register-school', SchoolsRegister::class)
    ->middleware('guest')
    ->name('schools.register');

Route::livewire('invitations/accept', AcceptInvitationPage::class)
    ->name('invitations.accept');

Route::get('invitations/{token}', ShowInvitationController::class)
    ->middleware('throttle:invitation-token')
    ->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => to_route('schools.index'));

    Route::livewire('schools', SchoolsIndex::class)->name('schools.index');
});

Route::prefix('{school}')
    ->whereUlid('school')
    ->middleware(['auth', 'verified', SetCurrentSchool::class])
    ->group(function () {
        Route::livewire('dashboard', Dashboard::class)->name('dashboard');

        Route::livewire('setup/academic-years', SetupAcademicYears::class)
            ->can('viewAny', AcademicYear::class)
            ->name('setup.academic-years');

        Route::livewire('setup/grades', SetupGrades::class)
            ->can('viewAny', Grade::class)
            ->name('setup.grades');

        Route::livewire('setup/sections', SetupSections::class)
            ->can('viewAny', Section::class)
            ->name('setup.sections');

        Route::livewire('setup/subjects', SetupSubjects::class)
            ->can('viewAny', Subject::class)
            ->name('setup.subjects');
    });

require __DIR__.'/settings.php';
