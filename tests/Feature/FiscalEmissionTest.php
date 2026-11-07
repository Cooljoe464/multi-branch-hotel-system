<?php

use App\Contracts\EInvoiceEmitter;
use App\Jobs\EmitEinvoiceJob;
use App\Jobs\OcrDocumentJob;
use App\Models\Branch;
use App\Models\CorporateAccount;
use App\Models\FiscalDocument;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Reservation;
use App\Services\Fiscal\FirsEmitter;
use App\Services\Fiscal\FiscalService;
use App\Services\FolioService;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Database\QueryException;
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

    Http::fake(['*' => Http::response([
        'irn' => 'FIRS-IRN-9',
        'state' => 'cleared',
        'digitalStamp' => 'stamp-9',
        'qrData' => 'qr-9',
    ], 200)]);

    $service = new FolioService;
    $folio = fiscalFolio($this->branch, $service);
    $service->recordPayment($folio, 25000, 'card', null, 'PAY-1');
    $service->checkout($folio);

    $document = FiscalDocument::sole();
    (new EmitEinvoiceJob($document->id))->handle();

    $document->refresh();

    expect($document->status)->toBe(FiscalDocument::STATUS_ISSUED)
        ->and($document->irn)->toBe('FIRS-IRN-9')
        ->and($document->digital_stamp)->toBe('stamp-9')
        ->and($document->qr_data)->toBe('qr-9')
        ->and($document->response_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($document->submitted_at)->not->toBeNull()
        ->and($document->cleared_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->header('Authorization')[0] ?? '', 'branch-key'));
});

it('keeps a provider reference submitted until the status endpoint confirms clearance', function () {
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.status_endpoint' => 'https://firs.example.test/status/{reference}']);
    config(['services.firs.api_key' => 'test-key']);
    Http::fake([
        'https://firs.example.test/invoices' => Http::response(['irn' => 'FIRS-PENDING-1'], 202),
        'https://firs.example.test/status/FIRS-PENDING-1' => Http::response([
            'irn' => 'FIRS-PENDING-1',
            'state' => 'cleared',
            'digitalStamp' => 'stamp-1',
        ], 200),
    ]);

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_READY,
        'payload' => [
            'invoiceNumber' => 'INV-1',
            'invoiceDate' => now()->toDateString(),
            'supplier' => ['name' => 'Hotel'],
            'lines' => [['description' => 'Room', 'amount' => 1000]],
            'totalAmount' => 1000,
        ],
        'idempotency_key' => 'fiscal-pending-1',
    ]);

    (new EmitEinvoiceJob($document->id))->handle();
    expect($document->refresh()->status)->toBe(FiscalDocument::STATUS_SUBMITTED);

    (new EmitEinvoiceJob($document->id))->handle();
    expect($document->refresh()->status)->toBe(FiscalDocument::STATUS_CLEARED)
        ->and($document->digital_stamp)->toBe('stamp-1');
});

it('creates a retry-safe credit note linked to its cleared original invoice', function () {
    Queue::fake();
    $emitter = Mockery::mock(FirsEmitter::class);
    $emitter->shouldReceive('provider')->andReturn('firs');
    $emitter->shouldReceive('capabilities')->andReturn([
        'submit' => true,
        'status' => true,
        'credit_note' => true,
        'reconcile' => true,
    ]);
    app()->instance(EInvoiceEmitter::class, $emitter);

    $original = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_CLEARED,
        'irn' => 'INV-IRN-1',
        'payload' => ['totalAmount' => 10000],
        'idempotency_key' => 'original-invoice-1',
    ]);
    $service = app(FiscalService::class);
    $payload = [
        'creditNoteNumber' => 'CN-1',
        'lines' => [['description' => 'Refund', 'amount' => 2500]],
        'totalAmount' => 2500,
    ];

    $creditNote = $service->issueCreditNote($original, $payload);
    $again = $service->issueCreditNote($original, $payload);

    expect($creditNote->id)->toBe($again->id)
        ->and($creditNote->document_type)->toBe('credit_note')
        ->and($creditNote->original_document_id)->toBe($original->id)
        ->and($creditNote->payload['originalInvoiceReference'])->toBe('INV-IRN-1');
    Queue::assertPushed(EmitEinvoiceJob::class, 1);
});

it('does not allow changing a fiscal invoice payload after it is ready', function () {
    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_READY,
        'payload' => ['invoiceNumber' => 'INV-LOCKED'],
        'idempotency_key' => 'fiscal-immutable-1',
    ]);

    expect(fn () => $document->update(['payload' => ['invoiceNumber' => 'INV-CHANGED']]))
        ->toThrow(QueryException::class);
});

it('holds corporate invoices until a valid buyer tax identity is available', function () {
    Queue::fake();
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);

    $corporate = CorporateAccount::create([
        'branch_id' => $this->branch->id,
        'name' => 'Example Group Ltd',
        'code' => 'EXAMPLE',
        'billing_address' => '10 Marina Road, Lagos',
        'tax_id' => null,
        'is_active' => true,
    ]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'corporate_account_id' => $corporate->id,
    ]);
    $folioService = new FolioService;
    $folio = $folioService->createFolio($this->branch->id, $reservation->id);
    $folioService->postDebit($folio, 'rooms', 'Room charge', 10000);
    $folioService->recordPayment($folio, 10750, 'card', null, 'B2B-PAY-1');
    $folioService->checkout($folio);

    $fiscal = app(FiscalService::class);
    $document = $fiscal->queueFor($folio->fresh());

    expect($document)->not->toBeNull()
        ->and($document->status)->toBe(FiscalDocument::STATUS_DRAFT)
        ->and($document->last_error)->toContain('13-digit Tax ID')
        ->and($document->payload['buyer']['name'])->toBe('Example Group Ltd')
        ->and($document->payload['buyer']['address'])->toBe('10 Marina Road, Lagos');
    Queue::assertNotPushed(EmitEinvoiceJob::class);

    $corporate->update(['tax_id' => '1234567890123']);
    $released = $fiscal->releaseHeldDocument($document);

    expect($released->status)->toBe(FiscalDocument::STATUS_READY)
        ->and($released->payload['buyer']['taxId'])->toBe('1234567890123')
        ->and($released->last_error)->toBeNull();
    Queue::assertPushed(EmitEinvoiceJob::class, 1);
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
