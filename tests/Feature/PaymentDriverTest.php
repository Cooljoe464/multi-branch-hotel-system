<?php

use App\Contracts\PaymentDriver;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\PaymentService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    app(BusinessDateService::class)->current($this->branch);
});

function fakeDriver(): PaymentDriver
{
    return new class implements PaymentDriver
    {
        private int $counter = 0;

        private function next(string $prefix): string
        {
            $this->counter++;

            return $prefix.'-'.$this->counter;
        }

        public function name(): string
        {
            return 'fake';
        }

        public function initialize(int $amountMinor, string $currency, string $email, array $metadata, ?string $callbackUrl = null): array
        {
            return ['authorization_url' => 'https://fake.test/pay', 'access_code' => 'FAKE-ACCESS', 'reference' => $this->next('FAKE-REF')];
        }

        public function verify(string $reference): array
        {
            return ['status' => 'success', 'reference' => $reference];
        }

        public function preauthorize(int $amountMinor, string $currency, string $token, array $metadata): array
        {
            return ['reference' => $this->next('FAKE-PRE'), 'authorization_code' => $this->next('FAKE-AUTH')];
        }

        public function capture(string $authorizationCode, int $amountMinor): array
        {
            return ['reference' => $this->next('FAKE-CAP'), 'captured_minor' => $amountMinor];
        }

        public function refund(string $gatewayReference, int $amountMinor): array
        {
            return ['reference' => $this->next('FAKE-REFUND'), 'refunded_minor' => $amountMinor];
        }

        public function void(string $gatewayReference): bool
        {
            return true;
        }
    };
}

it('runs the full pre-auth, capture and partial refund chain on any driver', function () {
    $service = new PaymentService(fakeDriver());
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Driver Guest');

    $method = PaymentMethod::create([
        'driver' => 'fake',
        'token' => 'tok_test_123',
        'brand' => 'visa',
        'last4' => '4242',
    ]);

    $preauth = $service->preauthorize($folio, $folio, $method, 20000);
    expect($preauth->kind)->toBe(PaymentTransaction::KIND_PREAUTH)
        ->and($preauth->status)->toBe('held');

    $capture = $service->capture($preauth, 15000, $this->user->id);
    expect($capture->kind)->toBe(PaymentTransaction::KIND_CAPTURE)
        ->and($capture->parent_id)->toBe($preauth->id)
        ->and($folio->fresh()->outstanding_balance)->toBe(-15000);

    // Second capture is allowed up to the held total.
    $service->capture($preauth->fresh(), 5000, $this->user->id);
    expect($preauth->fresh()->status)->toBe('captured');

    // Over-capture is rejected.
    expect(fn () => $service->capture($preauth->fresh(), 1, $this->user->id))
        ->toThrow(InvalidArgumentException::class);

    // Partial refund, then over-refund rejection.
    $charge = PaymentTransaction::where('kind', PaymentTransaction::KIND_CAPTURE)->firstOrFail();
    $refund = $service->refundPayment($charge, 4000, $this->user->id);
    expect($refund->kind)->toBe(PaymentTransaction::KIND_REFUND)
        ->and($refund->parent_id)->toBe($charge->id);

    expect(fn () => $service->refundPayment($charge->fresh(), 12000, $this->user->id))
        ->toThrow(InvalidArgumentException::class, 'Refund exceeds');

    // Refund journal uses the refund event legs.
    expect(JournalEntry::where('event', 'refund.issued')->count())->toBe(1);
});

it('contains no gateway transport in the abstracted service', function () {
    $source = file_get_contents(app_path('Services/PaymentService.php'));

    expect($source)->not->toContain('api.paystack.co')
        ->and($source)->not->toContain('Http::');
});

it('initializes paystack payments through the driver over HTTP', function () {
    Http::fake(['*' => Http::response([
        'data' => ['authorization_url' => 'https://paystack.test/pay', 'access_code' => 'ACC'],
    ], 200)]);

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Paystack Guest');

    $result = (new PaymentService)->initializePayment($folio, 5000, 'guest@example.com');

    expect($result['reference'])->toStartWith('HMS-');
    $this->assertDatabaseHas('payment_transactions', [
        'paystack_reference' => $result['reference'],
        'driver' => 'paystack',
        'kind' => PaymentTransaction::KIND_SALE,
    ]);
});
