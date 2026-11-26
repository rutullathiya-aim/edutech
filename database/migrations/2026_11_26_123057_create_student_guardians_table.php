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
        Schema::create('student_guardian', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('student_id');
            $table->ulid('guardian_id');
            $table->string('relationship', 20);
            $table->boolean('is_primary')->default(false);
            $table->tinyInteger('primary_flag')
                ->nullable()
                ->storedAs('CASE WHEN is_primary = 1 THEN 1 ELSE NULL END');
            $table->timestamps();

            $table->unique(['school_id', 'student_id', 'primary_flag'], 'sg_school_student_primary_unique');
            $table->unique(['school_id', 'student_id', 'guardian_id'], 'sg_school_student_guardian_unique');
            $table->unique(['school_id', 'id'], 'sg_school_id_unique');

            $table->foreign('school_id', 'sg_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'student_id'], 'sg_student_fk')
                ->references(['school_id', 'id'])
                ->on('students')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'guardian_id'], 'sg_guardian_fk')
                ->references(['school_id', 'id'])
                ->on('guardians')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_guardian');
    }
};
