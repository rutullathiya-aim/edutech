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
        Schema::create('students', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('admission_no', 30);
            $table->date('admission_date');
            $table->string('name', 150);
            $table->date('date_of_birth');
            $table->string('gender', 20);
            $table->string('social_category', 20)->nullable();
            $table->string('religion', 30)->nullable();
            $table->string('nationality', 50)->default('Indian');
            $table->string('mother_tongue', 50)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->char('apaar_id', 12)->nullable();
            $table->char('pen', 11)->nullable();
            $table->char('aadhaar_last4', 4)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->char('pincode', 6)->nullable();
            $table->string('previous_school', 150)->nullable();
            $table->string('previous_tc_no', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('consent_recorded_at')->nullable();
            $table->ulid('consent_recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'admission_no'], 'students_school_admission_unique');
            $table->unique(['school_id', 'apaar_id'], 'students_school_apaar_unique');
            $table->unique(['school_id', 'id'], 'students_school_id_unique');
            $table->index(['school_id', 'name'], 'students_school_name_index');
            $table->index(['school_id', 'status'], 'students_school_status_index');

            $table->foreign('school_id', 'students_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign('consent_recorded_by', 'students_consent_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
