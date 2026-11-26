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
        Schema::create('grade_subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('grade_id');
            $table->ulid('subject_id');
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['school_id', 'grade_id', 'subject_id'], 'gs_school_grade_subject_unique');
            $table->unique(['school_id', 'id'], 'gs_school_id_unique');

            $table->foreign('school_id', 'gs_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'grade_id'], 'gs_grade_fk')
                ->references(['school_id', 'id'])
                ->on('grades')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'subject_id'], 'gs_subject_fk')
                ->references(['school_id', 'id'])
                ->on('subjects')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_subjects');
    }
};
