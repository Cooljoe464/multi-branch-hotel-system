<?php

use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Services\Fiscal\AfriInvoiceProvider;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->secrets = app(SecretManager::class);
    $this->limiter = app(VendorRateLimiter::class);
    $this->provider = new AfriInvoiceProvider($this->secrets, $this->limiter);
});

it('reports capabilities correctly based on credentials', function () {
    expect($this->provider->capabilities($this->branch)['submit'])->toBeFalse();

    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_API_KEY, 'afri-test-key');
    expect($this->provider->capabilities($this->branch)['submit'])->toBeFalse();

    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_SERVICE_ID, 'SRV-12345');
    expect($this->provider->capabilities($this->branch)['submit'])->toBeTrue();
    expect($this->provider->capabilities($this->branch)['status'])->toBeTrue();
});

it('submits B2C invoice and records IRN, QR code, and digital stamp', function () {
    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_API_KEY, 'afri-test-key');
    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_SERVICE_ID, 'SRV-12345');
    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_ENVIRONMENT, 'sandbox');

    Http::fake([
        'https://sandbox.afrinvoice.com/api/v1/validate-invoice' => Http::response([
            'status' => 'CLEARED',
            'irn' => 'IRN-AFRI-2026-9999',
            'qr_code' => 'https://einvoice.nrs.gov.ng/verify/IRN-AFRI-2026-9999',
            'digital_stamp' => 'MEYCIQCc9d1k7b8z3k4l...',
            'cleared_at' => '2026-11-21T12:00:00Z',
        ], 200),
    ]);

    $doc = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'afri_invoice',
        'status' => FiscalDocument::STATUS_READY,
        'idempotency_key' => 'idem-afri-1',
        'payload' => [
            'invoiceNumber' => 'FOLIO-1001',
            'totalAmount' => 75000,
            'taxAmount' => 5250,
            'lines' => [
                ['description' => 'Deluxe Room', 'total_amount' => 75000],
            ],
        ],
    ]);

    $result = $this->provider->submit($doc, 'idem-afri-1');

    expect($result['ok'])->toBeTrue()
        ->and($result['irn'])->toBe('IRN-AFRI-2026-9999')
        ->and($result['state'])->toBe('CLEARED')
        ->and($result['qr_data'])->toContain('einvoice.nrs.gov.ng')
        ->and($result['digital_stamp'])->not->toBeEmpty();
});

it('submits B2B invoice with corporate buyer Tax ID', function () {
    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_API_KEY, 'afri-test-key');
    $this->secrets->set($this->branch, AfriInvoiceProvider::SECRET_SERVICE_ID, 'SRV-12345');

    Http::fake([
        'https://sandbox.afrinvoice.com/api/v1/validate-invoice' => function ($request) {
            $data = $request->data();
            expect($data['transaction_type'])->toBe('B2B')
                ->and($data['buyer']['tax_id'])->toBe('23480019283-0001');

            return Http::response([
                'status' => 'CLEARED',
                'irn' => 'IRN-B2B-CORP-456',
                'qr_code' => 'https://einvoice.nrs.gov.ng/verify/IRN-B2B-CORP-456',
                'digital_stamp' => 'STAMP-B2B-SECURE',
            ], 200);
        },
    ]);

    $doc = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'provider' => 'afri_invoice',
        'status' => FiscalDocument::STATUS_READY,
        'idempotency_key' => 'idem-afri-b2b',
        'payload' => [
            'invoiceNumber' => 'FOLIO-B2B-2002',
            'buyerTin' => '23480019283-0001',
            'buyerName' => 'Dangote Industries Ltd',
            'totalAmount' => 500000,
            'taxAmount' => 35000,
            'lines' => [
                ['description' => 'Conference Hall Hire', 'total_amount' => 500000],
            ],
        ],
    ]);

    $result = $this->provider->submit($doc, 'idem-afri-b2b');

    expect($result['ok'])->toBeTrue()
        ->and($result['irn'])->toBe('IRN-B2B-CORP-456')
        ->and($result['state'])->toBe('CLEARED');
});

it('tests credentials connection diagnostic successfully', function () {
    Http::fake([
        'https://sandbox.afrinvoice.com/api/v1/auth/verify' => Http::response([
            'merchant_name' => 'Eko Hotels & Suites Lagos',
            'active' => true,
        ], 200),
    ]);

    $diag = $this->provider->testConnection(null, 'valid-key', 'SRV-TEST', 'sandbox');

    expect($diag['ok'])->toBeTrue()
        ->and($diag['merchant_name'])->toBe('Eko Hotels & Suites Lagos')
        ->and($diag['message'])->toContain('Successfully connected');
});

it('handles test connection failures gracefully', function () {
    Http::fake([
        'https://sandbox.afrinvoice.com/api/v1/auth/verify' => Http::response([
            'message' => 'Invalid Service ID or API key signature.',
        ], 401),
    ]);

    $diag = $this->provider->testConnection(null, 'bad-key', 'SRV-BAD', 'sandbox');

    expect($diag['ok'])->toBeFalse()
        ->and($diag['message'])->toContain('Invalid Service ID');
});
