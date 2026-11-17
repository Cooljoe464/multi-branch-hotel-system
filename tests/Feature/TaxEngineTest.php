<?php

use App\Jobs\BackfillTaxProfilesJob;
use App\Jobs\EmitEinvoiceJob;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Models\Supplier;
use App\Models\TaxComponent;
use App\Models\TaxExemption;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Fiscal\FiscalService;
use App\Services\FolioService;
use App\Services\SupplierInputVatService;
use App\Services\TaxRuleVersionService;
use App\Services\TaxService;
use App\Services\VatWorkpaperService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

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

it('AC-046 apportions input VAT by taxable use', function () {
    expect((new TaxService)->apportionInputVat(10000, 750, 250))->toBe([
        'recoverable_minor' => 7500,
        'non_recoverable_minor' => 2500,
        'ratio_bps' => 7500,
    ]);
});

it('AC-046 apportions from the full ratio rather than rounded basis points', function () {
    expect((new TaxService)->apportionInputVat(1_000_000, 1, 5))->toBe([
        'recoverable_minor' => 166_667,
        'non_recoverable_minor' => 833_333,
        'ratio_bps' => 1667,
    ]);
});

it('AC-046 records supplier input VAT and builds the monthly workpaper from posted folios', function () {
    $profile = taxProfile($this->branch);
    $supplier = Supplier::query()->create(['branch_id' => $this->branch->id, 'name' => 'Taxable Supplies Ltd']);
    $folioService = new FolioService;
    $folio = $folioService->createStaffFolio($this->branch->id, 'VAT Workpaper Guest');
    $folioService->postManualCharge($folio, 'fnb', 'Taxable service', 10000, null, $this->user->id, null, $profile->id);

    $invoice = app(SupplierInputVatService::class)->record(
        branch: $this->branch,
        supplier: $supplier,
        actor: $this->user,
        invoiceNumber: 'SUP-INV-2026-001',
        invoiceDate: now()->toDateString(),
        supplyDate: now()->toDateString(),
        supplierTaxId: '1234567890123',
        netAmountMinor: 10000,
        vatAmountMinor: 1000,
        taxableUseMinor: 7500,
        exemptUseMinor: 2500,
        validatedEinvoice: true,
        einvoiceReference: 'NRS-SUP-001',
        evidenceReference: 'storage://supplier-invoices/SUP-INV-2026-001',
    );

    $workpaper = app(VatWorkpaperService::class)->forMonth($this->branch, now()->format('Y-m'));

    expect($workpaper['output_vat_minor'])->toBe(750)
        ->and($workpaper['input_vat_minor'])->toBe(1000)
        ->and($workpaper['recoverable_input_vat_minor'])->toBe(750)
        ->and($workpaper['non_recoverable_input_vat_minor'])->toBe(250)
        ->and($workpaper['net_vat_payable_minor'])->toBe(0)
        ->and($workpaper['supplier_invoice_ids'])->toBe([$invoice->id]);

    $this->artisan('tax:vat-workpaper', [
        'tenant' => $this->branch->tenant_id,
        'branch' => $this->branch->id,
        'month' => now()->format('Y-m'),
    ])
        ->expectsOutputToContain('"net_vat_payable_minor": 0')
        ->assertSuccessful();

    app(SupplierInputVatService::class)->void($invoice, $this->user, 'Duplicate supplier scan');

    expect(app(VatWorkpaperService::class)->forMonth($this->branch, now()->format('Y-m'))['recoverable_input_vat_minor'])->toBe(0);
});

it('AC-046 exposes supplier invoice entry and the monthly workpaper behind tax permissions', function () {
    $supplier = Supplier::query()->create(['branch_id' => $this->branch->id, 'name' => 'Invoice Entry Supplier']);
    $payload = [
        'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'HTTP-SUP-001',
        'supplier_tax_id' => '1234567890123',
        'invoice_date' => now()->toDateString(),
        'supply_date' => now()->toDateString(),
        'net_amount_minor' => 10000,
        'vat_amount_minor' => 750,
        'taxable_use_minor' => 10000,
        'exempt_use_minor' => 0,
        'validated_einvoice' => false,
        'einvoice_reference' => null,
        'evidence_reference' => 'storage://supplier-invoices/HTTP-SUP-001',
    ];

    $this->actingAs($this->user)
        ->postJson(route('tax.supplier-invoices.store', $this->branch), $payload)
        ->assertCreated()
        ->assertJsonPath('data.supplier_invoice_number', 'HTTP-SUP-001');

    $this->actingAs($this->user)
        ->getJson(route('tax.vat-workpaper', ['branch' => $this->branch, 'month' => now()->format('Y-m')]))
        ->assertOk()
        ->assertJsonPath('data.input_vat_minor', 750);

    $this->actingAs($this->user)
        ->postJson(route('tax.rule-versions.store', $this->branch), [
            'rule_key' => 'tenant.vat_status',
            'value' => ['status' => 'registered'],
            'effective_from' => now()->addYear()->toDateString(),
            'evidence_snapshot' => ['source' => 'tax-adviser-confirmation'],
            'approval_reference' => 'TAX-APPROVAL-HTTP-001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rule_key', 'tenant.vat_status');
});

it('AC-047 selects only the tax profile effective on the transaction date', function () {
    $branch = Branch::factory()->create();
    $expired = TaxProfile::create([
        'branch_id' => $branch->id,
        'jurisdiction' => 'NG',
        'name' => 'Expired VAT',
        'active' => true,
        'effective_from' => now()->subYear()->toDateString(),
        'effective_until' => now()->subDay()->toDateString(),
    ]);
    $current = TaxProfile::create([
        'branch_id' => $branch->id,
        'jurisdiction' => 'NG',
        'name' => 'Current VAT',
        'active' => true,
        'effective_from' => now()->subDay()->toDateString(),
    ]);

    expect((new TaxService)->profileForDate($branch->id, now())->id)->toBe($current->id);
    expect($expired->effectiveOn(now()))->toBeFalse()
        ->and($expired->effectiveOn(now()->subDay()))->toBeTrue()
        ->and(TaxProfile::query()->effectiveOn(now()->subDay())->whereKey($expired->id)->exists())->toBeTrue();
});

it('AC-047 applies the newest evidence-backed tax rule version on its effective date', function () {
    $profile = taxProfile($this->branch);
    $service = app(TaxRuleVersionService::class);
    $outsideApprover = User::factory()->create();

    expect(fn () => $service->publish(
        tenantId: $this->branch->tenant_id,
        branchId: $this->branch->id,
        ruleKey: 'tax_component.VAT.rate_bps',
        value: ['rate_bps' => 1000],
        effectiveFrom: '2026-11-11',
        effectiveUntil: null,
        evidenceSnapshot: ['source' => 'untrusted-approval-test'],
        approvalReference: 'TAX-APPROVAL-UNTRUSTED',
        approver: $outsideApprover,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $service->publish(
        tenantId: $this->branch->tenant_id,
        branchId: $this->branch->id,
        ruleKey: 'tax_component.VAT.rate_bps',
        value: ['rate_bps' => '1000'],
        effectiveFrom: '2026-11-11',
        effectiveUntil: null,
        evidenceSnapshot: ['source' => 'invalid-rate-test'],
        approvalReference: 'TAX-APPROVAL-INVALID',
        approver: $this->user,
    ))->toThrow(ValidationException::class);

    $service->publish(
        tenantId: $this->branch->tenant_id,
        branchId: $this->branch->id,
        ruleKey: 'tax_component.VAT.rate_bps',
        value: ['rate_bps' => 1000],
        effectiveFrom: '2026-11-11',
        effectiveUntil: null,
        evidenceSnapshot: ['source' => 'tax-adviser-approval'],
        approvalReference: 'TAX-APPROVAL-2026-11',
        approver: $this->user,
        creator: $this->user,
    );

    $beforeChange = app(TaxService::class)->compute(10000, $profile, 'fnb', effectiveDate: '2026-11-10');
    $afterChange = app(TaxService::class)->compute(10000, $profile, 'fnb', effectiveDate: '2026-11-11');

    expect($beforeChange['lines'][0]['rate_bps'])->toBe(750)
        ->and($beforeChange['snapshot']['rule_version_ids'])->toBe([])
        ->and($afterChange['lines'][0]['rate_bps'])->toBe(1000)
        ->and($afterChange['snapshot']['rule_version_ids'])->toHaveCount(1);

    $nextVersion = $service->publish(
        tenantId: $this->branch->tenant_id,
        branchId: $this->branch->id,
        ruleKey: 'tax_component.VAT.rate_bps',
        value: ['rate_bps' => 1200],
        effectiveFrom: '2026-11-15',
        effectiveUntil: null,
        evidenceSnapshot: ['source' => 'second-approval'],
        approvalReference: 'TAX-APPROVAL-2026-12',
        approver: $this->user,
        creator: $this->user,
    );

    $afterNextChange = app(TaxService::class)->compute(10000, $profile, 'fnb', effectiveDate: '2026-11-15');

    expect($afterNextChange['lines'][0]['rate_bps'])->toBe(1200)
        ->and($afterNextChange['snapshot']['rule_version_ids'])->toContain($nextVersion->id);
});

it('AC-047 applies the tenant VAT status effective on the transaction date', function () {
    $profile = taxProfile($this->branch);
    $status = app(TaxRuleVersionService::class)->publish(
        tenantId: $this->branch->tenant_id,
        branchId: null,
        ruleKey: 'tenant.vat_status',
        value: ['status' => 'exempt_small_business', 'turnover_minor' => 9_000_000_000, 'fixed_assets_minor' => 2_000_000_000],
        effectiveFrom: '2026-11-11',
        effectiveUntil: null,
        evidenceSnapshot: ['turnover_test' => 'passed', 'fixed_assets_test' => 'passed', 'professional_services' => false],
        approvalReference: 'TAX-STATUS-APPROVAL-2026',
        approver: $this->user,
        creator: $this->user,
    );

    $beforeChange = app(TaxService::class)->compute(10000, $profile, 'fnb', effectiveDate: '2026-11-10');
    $afterChange = app(TaxService::class)->compute(10000, $profile, 'fnb', effectiveDate: '2026-11-11');

    expect(array_column($beforeChange['lines'], 'code'))->toContain('VAT')
        ->and(array_column($afterChange['lines'], 'code'))->not->toContain('VAT')
        ->and($afterChange['snapshot']['vat_status'])->toBe('exempt_small_business')
        ->and($afterChange['snapshot']['vat_status_version_id'])->toBe($status->id);
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
    Http::fake(['*' => Http::response(['irn' => 'FIRS-IRN-1', 'state' => 'cleared'], 200)]);

    $service = new FolioService;
    $folio = $service->createStaffFolio($this->branch->id, 'Fiscal Guest');
    $service->postDebit($folio, 'rooms', 'Room charge', 25000, null, 750);

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => app(FiscalService::class)->payloadFor($folio),
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

    $service = new FolioService;
    $folio = $service->createStaffFolio($this->branch->id, 'Fiscal Guest');
    $service->postDebit($folio, 'rooms', 'Room charge', 25000, null, 750);

    $document = FiscalDocument::create([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'provider' => 'firs',
        'status' => FiscalDocument::STATUS_PENDING,
        'payload' => app(FiscalService::class)->payloadFor($folio),
        'idempotency_key' => 'fiscal-test-3',
    ]);

    // attempts=5 reaches the failure threshold on this run.
    $document->update(['attempts' => 4]);

    (new EmitEinvoiceJob($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe(FiscalDocument::STATUS_FAILED)
        ->and($document->last_error)->not->toBeNull();
});
