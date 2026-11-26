<?php

namespace Tests;

use App\Tenant\CurrentSchool;
use Database\Factories\AcademicYearFactory;
use Database\Factories\GradeFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CurrentSchool::forget();
        setPermissionsTeamId(null);
        AcademicYearFactory::resetYearSequence();
        GradeFactory::resetSequence();
    }

    protected function tearDown(): void
    {
        CurrentSchool::forget();
        setPermissionsTeamId(null);

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
