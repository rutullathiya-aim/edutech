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
        Schema::create('guardians', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name', 150);
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('occupation', 100)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'mobile'], 'guardians_school_mobile_index');
            $table->unique(['school_id', 'id'], 'guardians_school_id_unique');

            $table->foreign('school_id', 'guardians_school_fk')
                ->references('id')
                ->on('schools')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
