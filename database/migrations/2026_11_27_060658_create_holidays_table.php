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
        Schema::create('holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->ulid('academic_year_id');
            $table->date('date');
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['school_id', 'date'], 'holidays_school_date_unique');
            $table->unique(['school_id', 'id'], 'holidays_school_id_unique');
            $table->index(['school_id', 'academic_year_id', 'date'], 'holidays_school_year_date_index');

            $table->foreign('school_id', 'holidays_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();

            $table->foreign(['school_id', 'academic_year_id'], 'holidays_year_fk')
                ->references(['school_id', 'id'])
                ->on('academic_years')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
