<?php

use App\Actions\Students\Import\StudentImportColumns;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    StudentImportColumns::pruneExpiredTempFiles();
})
    ->name('prune-student-import-temp-files')
    ->hourly()
    ->withoutOverlapping();
