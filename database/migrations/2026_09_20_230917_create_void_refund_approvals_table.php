<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('void_refund_approvals', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');
            $table->foreignId('reason_code_id')->constrained('void_refund_codes');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('void_refund_approvals');
    }
};
