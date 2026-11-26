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
        Schema::create('subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('school_id');
            $table->string('name', 60);
            $table->string('code', 20)->nullable();
            $table->string('kind', 20);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'name'], 'subjects_school_name_unique');
            $table->unique(['school_id', 'code'], 'subjects_school_code_unique');
            $table->unique(['school_id', 'id'], 'subjects_school_id_unique');

            $table->foreign('school_id', 'subjects_school_fk')
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
        Schema::dropIfExists('subjects');
    }
};
