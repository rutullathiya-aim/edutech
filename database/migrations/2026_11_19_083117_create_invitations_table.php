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
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('email')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('role');
            $table->char('token_hash', 64)->unique();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->tinyInteger('open_flag')
                ->nullable()
                ->storedAs('CASE WHEN accepted_at IS NULL AND revoked_at IS NULL THEN 1 ELSE NULL END');
            $table->timestamps();

            $table->index(['school_id', 'email']);
            $table->index(['school_id', 'mobile']);
            $table->unique(['school_id', 'email', 'open_flag'], 'inv_school_email_open_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
