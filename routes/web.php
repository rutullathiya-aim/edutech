<?php

use App\Http\Middleware\SetCurrentSchool;
use App\Livewire\Schools\Index as SchoolsIndex;
use App\Livewire\Schools\Register as SchoolsRegister;
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
    });

require __DIR__.'/settings.php';
