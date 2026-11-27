<?php

use App\Enums\SchoolRole;
use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Spatie\Permission\Models\Role;

it('refuses to run DemoSeeder in production and creates no users', function () {
    $originalEnv = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => $this->artisan('db:seed', [
            '--class' => DemoSeeder::class,
            '--force' => true,
        ]))->toThrow(RuntimeException::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('schools', 0);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnv);
    }
});

it('seeds only roles and creates no test user when DatabaseSeeder runs in production', function () {
    $originalEnv = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        expect(Role::count())->toBe(count(SchoolRole::cases()));
        $this->assertDatabaseCount('users', 0);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnv);
    }
});

it('seeds 60 realistic idempotent demo students into Sunrise Public School without audit noise', function () {
    $this->artisan('db:seed', [
        '--class' => DemoSeeder::class,
        '--force' => true,
    ])->assertSuccessful();

    $sunrise = School::query()->where('name', 'Sunrise Public School')->firstOrFail();

    $firstStudentCount = Student::withoutGlobalScopes()->count();
    $firstGuardianCount = Guardian::withoutGlobalScopes()->count();
    $firstEnrollmentCount = Enrollment::withoutGlobalScopes()->count();
    $firstSectionCount = Section::withoutGlobalScopes()->count();
    $firstActivityCount = Activity::query()->count();

    expect($firstStudentCount)->toBe(60)
        ->and($firstEnrollmentCount)->toBe(60)
        ->and($firstSectionCount)->toBe(20) // Class 1-10 sections A & B in 2026-27
        ->and(Student::withoutGlobalScopes()->where('school_id', $sunrise->id)->count())->toBe(60)
        ->and(Student::withoutGlobalScopes()->where('status', StudentStatus::Left)->count())->toBe(2)
        ->and(Enrollment::withoutGlobalScopes()->whereNull('roll_number')->count())->toBe(4)
        ->and(Student::withoutGlobalScopes()->whereNotNull('apaar_id')->count())->toBe(30)
        ->and(Student::withoutGlobalScopes()->whereNull('apaar_id')->count())->toBe(30)
        ->and(Guardian::withoutGlobalScopes()->whereHas('studentGuardians', static fn ($query) => $query->withoutGlobalScopes(), '>=', 2)->count())->toBe(4)
        ->and(app(ActivityLogStatus::class)->disabled())->toBeFalse()
        ->and(Activity::query()->whereIn('log_name', ['student', 'guardian', 'student_guardian', 'enrollment'])->count())->toBe(0);

    // Every seeded section has 2-4 students.
    $sectionCounts = Section::withoutGlobalScopes()
        ->withCount(['enrollments' => static fn ($query) => $query->withoutGlobalScopes()])
        ->get()
        ->pluck('enrollments_count');
    expect($sectionCounts->min())->toBeGreaterThanOrEqual(2)
        ->and($sectionCounts->max())->toBeLessThanOrEqual(4);

    // Re-running DemoSeeder must not duplicate students, guardians, sections, or enrollments.
    $this->artisan('db:seed', [
        '--class' => DemoSeeder::class,
        '--force' => true,
    ])->assertSuccessful();

    expect(Student::withoutGlobalScopes()->count())->toBe($firstStudentCount)
        ->and(Guardian::withoutGlobalScopes()->count())->toBe($firstGuardianCount)
        ->and(Enrollment::withoutGlobalScopes()->count())->toBe($firstEnrollmentCount)
        ->and(Section::withoutGlobalScopes()->count())->toBe($firstSectionCount)
        ->and(Activity::query()->count())->toBe($firstActivityCount);
});
