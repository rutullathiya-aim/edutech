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
        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('academic_year_id');
            $table->ulid('section_id');
            $table->date('date');
            $table->ulid('taken_by');
            $table->timestamp('taken_at');
            $table->ulid('updated_by');
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('half_day_count')->default(0);
            $table->unsignedSmallInteger('leave_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['school_id', 'section_id', 'date'], 'att_sessions_school_section_date_unique');
            $table->unique(['school_id', 'id'], 'att_sessions_school_id_unique');
            $table->index(['school_id', 'date'], 'att_sessions_school_date_index');

            $table->foreign('school_id', 'att_sessions_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'academic_year_id', 'section_id'], 'att_sessions_section_fk')
                ->references(['school_id', 'academic_year_id', 'id'])
                ->on('sections')
                ->restrictOnDelete();

            $table->foreign('taken_by', 'att_sessions_taken_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('updated_by', 'att_sessions_updated_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
