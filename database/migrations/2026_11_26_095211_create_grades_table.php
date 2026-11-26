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
        Schema::create('grades', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('name', 30);
            $table->string('short_name', 10)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'name'], 'grades_school_name_unique');
            $table->unique(['school_id', 'sort_order'], 'grades_school_sort_unique');
            $table->unique(['school_id', 'id'], 'grades_school_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
