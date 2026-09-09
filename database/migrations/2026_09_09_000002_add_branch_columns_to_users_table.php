<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->boolean('is_global_admin')->default(false);
            $table->timestamp('gdpr_consent_at')->nullable();
            $table->string('gdpr_consent_version')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'branch_id',
                'is_global_admin',
                'gdpr_consent_at',
                'gdpr_consent_version',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
