<?php

use App\Services\GuestDedupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->foreignId('master_guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('dedup_hash', 64)->nullable()->index();
        });

        // Backfill hashes only; historic dupes are flagged for review,
        // never auto-merged.
        DB::table('guests')->orderBy('id')->chunkById(500, function ($guests) {
            foreach ($guests as $guest) {
                DB::table('guests')->where('id', $guest->id)->update([
                    'dedup_hash' => GuestDedupService::hashFor(
                        (string) ($guest->first_name ?? ''),
                        (string) ($guest->last_name ?? ''),
                        $guest->phone,
                        $guest->date_of_birth,
                    ),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_guest_id');
            $table->dropColumn('dedup_hash');
        });
    }
};
