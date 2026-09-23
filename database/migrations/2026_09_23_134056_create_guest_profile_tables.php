<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_merge_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surviving_guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('retired_guest_id')->constrained('guests')->cascadeOnDelete();
            $table->foreignId('merged_by')->constrained('users')->cascadeOnDelete();
            $table->json('field_choices')->nullable();
            $table->timestamps();

            $table->unique(['surviving_guest_id', 'retired_guest_id']);
        });

        Schema::create('do_not_rent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            // Email match covers bookings made before a guest row exists.
            $table->string('email')->nullable();
            $table->string('reason', 256);
            $table->foreignId('listed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'email']);
        });

        Schema::create('guest_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 16);
            $table->text('doc_number_enc')->nullable();
            $table->string('scan_path', 256)->nullable();
            $table->json('ocr_result')->nullable();
            $table->string('ocr_status', 16)->default('pending');
            $table->timestamps();

            $table->index(['guest_id', 'doc_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_identity_documents');
        Schema::dropIfExists('do_not_rent');
        Schema::dropIfExists('guest_merge_links');
    }
};
