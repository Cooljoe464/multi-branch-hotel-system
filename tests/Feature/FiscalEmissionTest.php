<?php

use App\Jobs\EmitEinvoiceJob;
use App\Jobs\OcrDocumentJob;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Services\Fiscal\FirsEmitter;
use App\Services\FolioService;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
});

function fiscalFolio(Branch $branch, FolioService $service, int $amount = 25000): Folio
{
    $folio = $service->createStaffFolio($branch->id, 'Fiscal Guest');
    $service->postDebit($folio, 'rooms', 'Room charge', $amount, null, 750);

    return $folio;
}

it('queues one e-invoice when a settled folio closes', function () {
    Queue::fake();
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);

    $service = new FolioService;
    $folio = fiscalFolio($this->branch, $service);
    $service->recordPayment($folio, 25000, 'card', null, 'PAY-1');
    $service->checkout($folio);

    $document = FiscalDocument::sole();

    expect($document->status)->toBe(FiscalDocument::STATUS_PENDING)
        ->and($document->branch_id)->toBe($this->branch->id)
        ->and($document->payload['invoiceNumber'] ?? null)->toBe($folio->folio_number)
        ->and($document->payload['lines'])->toHaveCount(1)
        ->and($document->payload['taxAmount'])->toBe(1875)
        ->and($document->payload['totalAmount'])->toBe(26875);

    Queue::assertPushed(EmitEinvoiceJob::class, 1);
});

it('never queues fiscal emission when the branch has no credentials', function () {
    Queue::fake();

    $service = new FolioService;
    $folio = fiscalFolio($this->branch, $service);
    $service->recordPayment($folio, 25000, 'card', null, 'PAY-1');
    $service->checkout($folio);

    expect(FiscalDocument::query()->count())->toBe(0);
    Queue::assertNotPushed(EmitEinvoiceJob::class);
});

it('emits through a branch-scoped secret that overrides the environment', function () {
    $secrets = new SecretManager;
    $secrets->set($this->branch, FirsEmitter::SECRET_ENDPOINT, 'https://firs.example.test/invoices');
    $secrets->set($this->branch, FirsEmitter::SECRET_API_KEY, 'branch-key');

    Http::fake(['*' => Http::response(['irn' => 'FIRS-IRN-9'], 200)]);

    $service = new FolioService;
    $folio = fiscalFolio($this->branch, $service);
    $service->recordPayment($folio, 25000, 'card', null, 'PAY-1');
    $service->checkout($folio);

    $document = FiscalDocument::sole();
    (new EmitEinvoiceJob($document->id))->handle();

    $document->refresh();

    expect($document->status)->toBe(FiscalDocument::STATUS_ISSUED)
        ->and($document->irn)->toBe('FIRS-IRN-9');

    Http::assertSent(fn ($request) => str_contains($request->header('Authorization')[0] ?? '', 'branch-key'));
});

it('refuses to sign a malformed payload instead of sending it', function () {
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);
    Http::fake(['*' => Http::response(['irn' => 'FIRS-IRN-1'], 200)]);

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => ['invoice' => 'made up by a model'],
        'idempotency_key' => 'fiscal-malformed-1',
    ]);

    (new EmitEinvoiceJob($document->id))->handle();

    $document->refresh();

    expect($document->status)->toBe(FiscalDocument::STATUS_PENDING)
        ->and($document->last_error)->toContain('invoiceNumber');

    Http::assertNothingSent();
});

it('holds emission when the branch rate-limit bucket is empty', function () {
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);
    Http::fake(['*' => Http::response(['irn' => 'FIRS-IRN-1'], 200)]);

    $limiter = app(VendorRateLimiter::class);

    for ($i = 0; $i < FirsEmitter::RATE_LIMIT; $i++) {
        expect($limiter->attempt('firs', $this->branch->id, FirsEmitter::RATE_LIMIT, 60))->toBeTrue();
    }

    expect($limiter->attempt('firs', $this->branch->id, FirsEmitter::RATE_LIMIT, 60))->toBeFalse();

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => ['invoiceNumber' => 'INV-1'],
        'idempotency_key' => 'fiscal-throttled-1',
    ]);

    (new EmitEinvoiceJob($document->id))->handle();

    expect($document->refresh()->last_error)->toContain('rate limit');

    Http::assertNothingSent();
});

it('runs OCR with platform-scoped secrets and scrubs the vendor payload', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('identity/7/scan.jpg', 'bytes');

    $secrets = new SecretManager;
    $secrets->set(null, 'ocr.endpoint', 'https://ocr.test/parse');
    $secrets->set(null, 'ocr.api_key', 'platform-key');

    Http::fake(['*' => Http::response([
        'full_name' => "Ada\x00Lovelace",
        'doc_number' => 'A1234567',
        'nested' => ['leak' => 'nope'],
        'blurb' => str_repeat('a', 600),
    ], 200)]);

    $document = GuestIdentityDocument::create([
        'guest_id' => Guest::factory()->create()->id,
        'doc_type' => 'passport',
        'doc_number' => 'A1234567',
        'scan_path' => 'identity/7/scan.jpg',
        'ocr_status' => GuestIdentityDocument::STATUS_PENDING,
    ]);

    (new OcrDocumentJob($document->id))->handle(
        app(SecretManager::class),
        app(VendorRateLimiter::class),
    );

    $document->refresh();

    expect($document->ocr_status)->toBe(GuestIdentityDocument::STATUS_DONE)
        ->and($document->ocr_result['full_name'] ?? null)->toBe('AdaLovelace')
        ->and($document->ocr_result)->not->toHaveKey('nested')
        ->and(mb_strlen((string) ($document->ocr_result['blurb'] ?? '')))->toBe(512);

    Http::assertSent(fn ($request) => str_contains($request->header('Authorization')[0] ?? '', 'platform-key'));
});

it('defers OCR while the vendor bucket is empty', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('identity/7/scan.jpg', 'bytes');

    config(['services.ocr.endpoint' => 'https://ocr.test/parse']);
    config(['services.ocr.key' => 'test-key']);
    Http::fake(['*' => Http::response(['full_name' => 'Ada'], 200)]);

    $document = GuestIdentityDocument::create([
        'guest_id' => Guest::factory()->create()->id,
        'doc_type' => 'passport',
        'scan_path' => 'identity/7/scan.jpg',
        'ocr_status' => GuestIdentityDocument::STATUS_PENDING,
    ]);

    $limiter = app(VendorRateLimiter::class);

    for ($i = 0; $i < OcrDocumentJob::RATE_LIMIT; $i++) {
        $limiter->attempt('ocr', null, OcrDocumentJob::RATE_LIMIT, 60);
    }

    (new OcrDocumentJob($document->id))->handle(app(SecretManager::class), $limiter);

    expect($document->refresh()->ocr_status)->toBe(GuestIdentityDocument::STATUS_PENDING);

    Http::assertNothingSent();
});
