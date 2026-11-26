<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('name', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->tinyInteger('current_flag')
                ->nullable()
                ->storedAs('CASE WHEN is_current = 1 THEN 1 ELSE NULL END');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'name'], 'ay_school_name_unique');
            $table->unique(['school_id', 'current_flag'], 'ay_school_current_unique');
            $table->unique(['school_id', 'id'], 'ay_school_id_unique');
            $table->index(['school_id', 'starts_on'], 'ay_school_starts_index');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE academic_years ADD CONSTRAINT ay_dates_check CHECK (ends_on > starts_on)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
