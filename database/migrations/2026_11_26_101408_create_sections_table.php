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
        Schema::create('sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('academic_year_id');
            $table->ulid('grade_id');
            $table->string('name', 10);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room', 30)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'academic_year_id', 'grade_id', 'name'], 'sections_name_unique');
            $table->unique(['school_id', 'id'], 'sections_school_id_unique');

            $table->foreign('school_id', 'sections_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'academic_year_id'], 'sections_year_fk')
                ->references(['school_id', 'id'])
                ->on('academic_years')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'grade_id'], 'sections_grade_fk')
                ->references(['school_id', 'id'])
                ->on('grades')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
