<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Jobs\OcrDocumentJob;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Identity capture: scans go to private R2, numbers are encrypted at
 * rest, OCR runs queued with PII-redacted logs. OCR outages degrade
 * to manual entry flagged ocr_pending — capture never blocks check-in.
 */
class IdentityService
{
    public const TYPES = ['nin', 'passport', 'drivers'];

    /**
     * @param  array<string, mixed>  $ocr
     */
    public function capture(
        Guest $guest,
        string $docType,
        ?string $docNumber,
        ?UploadedFile $scan,
        User $by,
        array $ocr = [],
    ): GuestIdentityDocument {
        if (! in_array($docType, self::TYPES, true)) {
            throw new AvailabilityException('ID_TYPE', "Unknown document type {$docType}.");
        }

        return DB::transaction(function () use ($guest, $docType, $docNumber, $scan, $by, $ocr) {
            $path = null;

            if ($scan) {
                $stored = $scan->store("identity/{$guest->id}", 'r2');

                if (! is_string($stored)) {
                    throw new AvailabilityException('ID_SCAN', 'Identity scan upload failed.');
                }

                $path = $stored;
            }

            $doc = GuestIdentityDocument::create([
                'guest_id' => $guest->id,
                'doc_type' => $docType,
                'doc_number' => $docNumber,
                'scan_path' => $path,
                'ocr_result' => $ocr === [] ? null : $ocr,
                'ocr_status' => $path !== null
                    ? GuestIdentityDocument::STATUS_PENDING
                    : GuestIdentityDocument::STATUS_MANUAL,
            ]);

            if ($path !== null) {
                OcrDocumentJob::dispatch($doc->id);
            }

            activity('guests')
                ->performedOn($guest)
                ->causedBy($by)
                ->withProperties(['doc_id' => $doc->id, 'doc_type' => $docType])
                ->log("Identity document captured ({$docType}).");

            return $doc->fresh() ?? $doc;
        });
    }

    /**
     * Signed, expiring scan URL. Requires guests.view_pii and logs the
     * unmask — masked by default everywhere else.
     */
    public function scanUrl(GuestIdentityDocument $doc, User $by, int $ttlMinutes = 15): string
    {
        if (! $by->can('guests.view_pii') && ! (bool) ($by->is_global_admin ?? false)) {
            throw new AvailabilityException('PII_FORBIDDEN', 'Viewing identity scans requires the guests.view_pii permission.');
        }

        if (! is_string($doc->scan_path) || $doc->scan_path === '') {
            throw new AvailabilityException('ID_SCAN_MISSING', 'This document has no stored scan.');
        }

        activity('guests')
            ->performedOn($doc->guest)
            ->causedBy($by)
            ->withProperties(['doc_id' => $doc->id])
            ->log("Identity scan viewed ({$doc->doc_type}).");

        return Storage::disk('r2')->temporaryUrl($doc->scan_path, now()->addMinutes($ttlMinutes));
    }

    /**
     * Plaintext number for authorized viewers only (logged). All other
     * surfaces show the masked form.
     */
    public function revealNumber(GuestIdentityDocument $doc, User $by): ?string
    {
        if (! $by->can('guests.view_pii') && ! (bool) ($by->is_global_admin ?? false)) {
            throw new AvailabilityException('PII_FORBIDDEN', 'Viewing identity numbers requires the guests.view_pii permission.');
        }

        activity('guests')
            ->performedOn($doc->guest)
            ->causedBy($by)
            ->withProperties(['doc_id' => $doc->id])
            ->log("Identity number revealed ({$doc->doc_type}).");

        return $doc->doc_number;
    }

    public static function mask(?string $number): string
    {
        if (! is_string($number) || strlen($number) <= 4) {
            return '••••';
        }

        return '••••'.substr($number, -4);
    }
}
