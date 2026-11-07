<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->string('document_type', 16)->default('invoice');
            $table->foreignId('original_document_id')->nullable()
                ->constrained('fiscal_documents')->restrictOnDelete();
            $table->text('digital_stamp')->nullable();
            $table->text('qr_data')->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('cleared_at')->nullable();
        });

        DB::table('fiscal_documents')->where('status', 'pending')->update(['status' => 'ready']);
        DB::table('fiscal_documents')->where('status', 'issued')->update(['status' => 'cleared']);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hospitality_guard_fiscal_document()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $fiscal_document$
            BEGIN
                IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'ready', 'failed') THEN
                    RAISE EXCEPTION 'Fiscal document cannot transition from draft to %', NEW.status
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'ready' AND NEW.status NOT IN ('ready', 'submitted', 'failed') THEN
                    RAISE EXCEPTION 'Fiscal document cannot transition from ready to %', NEW.status
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'submitted' AND NEW.status NOT IN ('submitted', 'cleared', 'failed') THEN
                    RAISE EXCEPTION 'Fiscal document cannot transition from submitted to %', NEW.status
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('cleared', 'failed') AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'Terminal fiscal document state % is immutable', OLD.status
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status <> 'draft' AND NEW.payload::jsonb IS DISTINCT FROM OLD.payload::jsonb THEN
                    RAISE EXCEPTION 'Fiscal invoice payload is immutable after it leaves draft'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status <> 'draft' AND ROW(
                    NEW.branch_id, NEW.folio_id, NEW.provider, NEW.document_type,
                    NEW.original_document_id, NEW.idempotency_key
                ) IS DISTINCT FROM ROW(
                    OLD.branch_id, OLD.folio_id, OLD.provider, OLD.document_type,
                    OLD.original_document_id, OLD.idempotency_key
                ) THEN
                    RAISE EXCEPTION 'Fiscal document identity is immutable after it leaves draft'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.irn IS NOT NULL AND NEW.irn IS DISTINCT FROM OLD.irn THEN
                    RAISE EXCEPTION 'Fiscal invoice reference is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.digital_stamp IS NOT NULL AND NEW.digital_stamp IS DISTINCT FROM OLD.digital_stamp THEN
                    RAISE EXCEPTION 'Fiscal digital stamp is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.qr_data IS NOT NULL AND NEW.qr_data IS DISTINCT FROM OLD.qr_data THEN
                    RAISE EXCEPTION 'Fiscal QR data is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.response_hash IS NOT NULL AND NEW.response_hash IS DISTINCT FROM OLD.response_hash THEN
                    RAISE EXCEPTION 'Fiscal provider response hash is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.cleared_at IS NOT NULL AND NEW.cleared_at IS DISTINCT FROM OLD.cleared_at THEN
                    RAISE EXCEPTION 'Fiscal clearance time is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.submitted_at IS NOT NULL AND NEW.submitted_at IS DISTINCT FROM OLD.submitted_at THEN
                    RAISE EXCEPTION 'Fiscal submission time is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.document_type NOT IN ('invoice', 'credit_note') THEN
                    RAISE EXCEPTION 'Unsupported fiscal document type %', NEW.document_type
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.document_type = 'credit_note' AND NEW.original_document_id IS NULL THEN
                    RAISE EXCEPTION 'Credit notes must reference their original invoice'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.document_type = 'credit_note' AND NOT EXISTS (
                    SELECT 1 FROM fiscal_documents AS original_invoice
                    WHERE original_invoice.id = NEW.original_document_id
                        AND original_invoice.branch_id = NEW.branch_id
                        AND original_invoice.document_type = 'invoice'
                        AND original_invoice.status = 'cleared'
                        AND original_invoice.irn IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Credit note original must be a cleared invoice for the same property'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $fiscal_document$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal_document_immutability_trigger
            BEFORE UPDATE ON fiscal_documents
            FOR EACH ROW EXECUTE FUNCTION hospitality_guard_fiscal_document()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fiscal_document_immutability_trigger ON fiscal_documents');
        DB::statement('DROP FUNCTION IF EXISTS hospitality_guard_fiscal_document()');
        DB::table('fiscal_documents')->where('status', 'submitted')->update(['status' => 'pending']);
        DB::table('fiscal_documents')->where('status', 'ready')->update(['status' => 'pending']);
        DB::table('fiscal_documents')->where('status', 'cleared')->update(['status' => 'issued']);

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_document_id');
            $table->dropColumn([
                'document_type',
                'digital_stamp',
                'qr_data',
                'response_hash',
                'submitted_at',
                'cleared_at',
            ]);
        });
    }
};
