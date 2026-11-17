<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION hospitality_guard_fiscal_document_retry()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $fiscal_retry$
            BEGIN
                IF OLD.irn IS NOT NULL OR NEW.irn IS NOT NULL THEN
                    RAISE EXCEPTION 'Accepted fiscal documents cannot be resubmitted'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.payload::jsonb IS DISTINCT FROM OLD.payload::jsonb
                    OR ROW(
                        NEW.branch_id, NEW.folio_id, NEW.provider, NEW.document_type,
                        NEW.original_document_id, NEW.idempotency_key
                    ) IS DISTINCT FROM ROW(
                        OLD.branch_id, OLD.folio_id, OLD.provider, OLD.document_type,
                        OLD.original_document_id, OLD.idempotency_key
                    )
                    OR ROW(
                        NEW.digital_stamp, NEW.qr_data, NEW.response_hash,
                        NEW.submitted_at, NEW.cleared_at
                    ) IS DISTINCT FROM ROW(
                        OLD.digital_stamp, OLD.qr_data, OLD.response_hash,
                        OLD.submitted_at, OLD.cleared_at
                    ) THEN
                    RAISE EXCEPTION 'Fiscal retry cannot change the document payload or identity'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $fiscal_retry$
            SQL);

        DB::statement('DROP TRIGGER fiscal_document_immutability_trigger ON fiscal_documents');
        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal_document_immutability_trigger
            BEFORE UPDATE ON fiscal_documents
            FOR EACH ROW
            WHEN (OLD.status IS DISTINCT FROM 'failed' OR NEW.status IS DISTINCT FROM 'ready')
            EXECUTE FUNCTION hospitality_guard_fiscal_document()
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal_document_safe_retry_trigger
            BEFORE UPDATE ON fiscal_documents
            FOR EACH ROW
            WHEN (OLD.status = 'failed' AND NEW.status = 'ready')
            EXECUTE FUNCTION hospitality_guard_fiscal_document_retry()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fiscal_document_safe_retry_trigger ON fiscal_documents');
        DB::statement('DROP TRIGGER IF EXISTS fiscal_document_immutability_trigger ON fiscal_documents');
        DB::statement('DROP FUNCTION IF EXISTS hospitality_guard_fiscal_document_retry()');
        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal_document_immutability_trigger
            BEFORE UPDATE ON fiscal_documents
            FOR EACH ROW EXECUTE FUNCTION hospitality_guard_fiscal_document()
            SQL);
    }
};
