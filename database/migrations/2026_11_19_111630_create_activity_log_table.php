<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUlidMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableUlidMorphs('causer');
            $table->foreignUlid('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('properties')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
