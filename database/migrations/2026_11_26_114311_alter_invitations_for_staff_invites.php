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
        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('name', 100)->default('')->after('school_id');
            $table->foreignUlid('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable()->after('invited_by');
            $table->unsignedSmallInteger('send_count')->default(1)->after('last_sent_at');
            $table->tinyInteger('open_flag')
                ->nullable()
                ->storedAs('CASE WHEN accepted_at IS NULL AND revoked_at IS NULL THEN 1 ELSE NULL END')
                ->after('revoked_at');

            $table->unique(['school_id', 'email', 'open_flag'], 'inv_school_email_open_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropUnique('inv_school_email_open_unique');
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['name', 'last_sent_at', 'send_count', 'open_flag']);
        });
    }
};
