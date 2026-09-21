<?php

use App\Jobs\BackfillTaxProfilesJob;
use App\Jobs\EmitEinvoiceJob;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Models\TaxComponent;
use App\Models\TaxExemption;
use App\Models\TaxProfile;
use App\Services\FolioService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['tax_rate' => 7.5]);
    $this->user = $this->makeAdminUser($this->branch);
});

function taxProfile(Branch $branch): TaxProfile
{
    $profile = TaxProfile::create([
        'branch_id' => $branch->id,
        'jurisdiction' => 'NG-LA',
        'name' => 'Lagos hospitality taxes',
        'active' => true,
    ]);

    TaxComponent::create([
        'tax_profile_id' => $profile->id,
        'code' => 'VAT',
        'mode' => TaxComponent::MODE_EXCLUSIVE,
        'rate_bps' => 750,
        'applies_to' => 'all',
        'sequence' => 0,
    ]);

    TaxComponent::create([
        'tax_profile_id' => $profile->id,
        'code' => 'SERVICE_CHARGE',
        'mode' => TaxComponent::MODE_EXCLUSIVE,
        'rate_bps' => 500,
        'applies_to' => 'fnb',
        'sequence' => 1,
    ]);

    TaxComponent::create([
        'tax_profile_id' => $profile->id,
        'code' => 'OCCUPANCY_LEVY',
        'mode' => TaxComponent::MODE_INCLUSIVE,
        'rate_bps' => 500,
        'applies_to' => 'room',
        'sequence' => 2,
    ]);

    return $profile->fresh();
}

it('computes exclusive taxes with integer math', function () {
    $profile = taxProfile($this->branch);

    // fnb 10,000 minor: VAT 750, then service charge 5% on the running
    // base (10,750) = 538. Stacked total 1,288.
    $result = app(TaxService::class)->compute(10000, $profile, 'fnb');

    expect($result['total_minor'])->toBe(1288)
        ->and($result['lines'])->toBe([
            ['code' => 'VAT', 'mode' => 'exclusive', 'rate_bps' => 750, 'amount_minor' => 750],
            ['code' => 'SERVICE_CHARGE', 'mode' => 'exclusive', 'rate_bps' => 500, 'amount_minor' => 538],
        ]);
});

it('extracts inclusive levies from the gross', function () {
    $profile = taxProfile($this->branch);

    // room 10,500 gross with 5% inclusive levy: 500 extracted, net 10,000.
    // VAT then applies on the net: 750. Total 1,250.
    $result = app(TaxService::class)->compute(10500, $profile, 'room');

    expect($result['net_minor'])->toBe(10000)
        ->and($result['total_minor'])->toBe(1250);
});

it('honours exemptions per component', function () {
    $profile = taxProfile($this->branch);

    TaxExemption::create([
        'branch_id' => $this->branch->id,
        'component_code' => 'VAT',
        'reason' => 'Diplomatic exemption',
    ]);

    $exempt = TaxExemption::where('branch_id', $this->branch->id)->pluck('component_code')->all();
    $result = app(TaxService::class)->compute(10000, $profile, 'fnb', $exempt);

    // Only the service charge remains: 500 on the base.
    expect($result['total_minor'])->toBe(500)
        ->and($result['snapshot']['exempt'])->toBe(['VAT']);
});

it('freezes the snapshot on the folio line', function () {
    $profile = taxProfile($this->branch);
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Tax Guest');

    $tx = (new FolioService)->postManualCharge($folio, 'fnb', 'Dinner', 10000, null, $this->user->id, null, $profile->id);

    expect($tx->tax_total_minor)->toBe(1288)
        ->and($tx->tax_snapshot['profile_id'])->toBe($profile->id)
        ->and($tx->tax_snapshot['lines'])->toHaveCount(2);

    // Changing the profile rate does not rewrite history.
    TaxComponent::where('tax_profile_id', $profile->id)->where('code', 'VAT')->update(['rate_bps' => 1000]);

    expect($tx->fresh()->tax_total_minor)->toBe(1288);
});

it('backfills a default profile from the legacy branch rate', function () {
    $stats = app(BackfillTaxProfilesJob::class)->handle();

    expect($stats['profiles'])->toBe(1);

    $profile = TaxProfile::forBranch($this->branch->id)->active()->firstOrFail();
    expect($profile->jurisdiction)->toBe('NG-DEFAULT');
    expect($profile->components()->where('code', 'VAT')->firstOrFail()->rate_bps)->toBe(750);

    $again = app(BackfillTaxProfilesJob::class)->handle();
    expect($again['profiles'])->toBe(0);
});

it('issues the IRN and records failures with retry', function () {
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);
    Http::fake(['*' => Http::response(['irn' => 'FIRS-IRN-1'], 200)]);

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Fiscal Guest');

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => ['invoice' => 'INV-1'],
        'idempotency_key' => 'fiscal-test-2',
    ]);

    $job = new EmitEinvoiceJob($document->id);
    $job->handle();

    $document->refresh();
    expect($document->status)->toBe(FiscalDocument::STATUS_ISSUED)
        ->and($document->irn)->toBe('FIRS-IRN-1');

    Http::assertSentCount(1);

    // Re-running an issued document sends nothing.
    $job->handle();
    Http::assertSentCount(1);
});

it('fails the document after exhausting retries', function () {
    config(['services.firs.endpoint' => 'https://firs.example.test/invoices']);
    config(['services.firs.api_key' => 'test-key']);
    Http::fake(['*' => Http::response('boom', 500)]);

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Fiscal Guest');

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => ['invoice' => 'INV-1'],
        'idempotency_key' => 'fiscal-test-3',
    ]);

    // attempts=5 reaches the failure threshold on this run.
    $document->update(['attempts' => 4]);

    (new EmitEinvoiceJob($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe(FiscalDocument::STATUS_FAILED)
        ->and($document->last_error)->not->toBeNull();
});
