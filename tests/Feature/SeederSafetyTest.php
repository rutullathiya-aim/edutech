<?php

use App\Enums\SchoolRole;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
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
