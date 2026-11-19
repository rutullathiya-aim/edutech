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
        Schema::create('schools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('status')->default('trial');
            $table->string('board')->nullable();
            $table->string('affiliation_number')->nullable();
            $table->string('udise_code', 11)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 6)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('locale')->default('en');
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedInteger('student_limit')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
