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
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('attendance_session_id');
            $table->ulid('enrollment_id');
            $table->date('date');
            $table->string('status', 10);
            $table->string('remark', 100)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'attendance_session_id', 'enrollment_id'], 'att_records_session_enrollment_unique');
            $table->unique(['school_id', 'enrollment_id', 'date'], 'att_records_school_enrollment_date_unique');

            $table->foreign('school_id', 'att_records_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'attendance_session_id'], 'att_records_session_fk')
                ->references(['school_id', 'id'])
                ->on('attendance_sessions')
                ->cascadeOnDelete();

            $table->foreign(['school_id', 'enrollment_id'], 'att_records_enrollment_fk')
                ->references(['school_id', 'id'])
                ->on('enrollments')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
