<?php

use App\Http\Middleware\SetCurrentSchool;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('dashboard', function () {
    $school = auth()->user()?->activeSchools()->first();

    if ($school) {
        return redirect()->route('dashboard', ['school' => $school->id]);
    }

    return redirect()->route('home');
})->middleware(['auth', 'verified']);

Route::prefix('{school}')
    ->whereUlid('school')
    ->middleware(['auth', 'verified', SetCurrentSchool::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

require __DIR__.'/settings.php';
