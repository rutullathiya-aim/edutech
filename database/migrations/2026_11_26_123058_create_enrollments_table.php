<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id');
            $table->ulid('academic_year_id');
            $table->ulid('section_id');
            $table->unsignedSmallInteger('roll_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('enrolled_on');
            $table->date('left_on')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'student_id'], 'enr_school_year_student_unique');
            $table->unique(['school_id', 'section_id', 'roll_number'], 'enr_school_section_roll_unique');
            $table->unique(['school_id', 'id'], 'enr_school_id_unique');

            $table->foreign('school_id', 'enr_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'student_id'], 'enr_student_fk')
                ->references(['school_id', 'id'])
                ->on('students')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'academic_year_id'], 'enr_year_fk')
                ->references(['school_id', 'id'])
                ->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'academic_year_id', 'section_id'], 'enr_section_fk')
                ->references(['school_id', 'academic_year_id', 'id'])
                ->on('sections')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
