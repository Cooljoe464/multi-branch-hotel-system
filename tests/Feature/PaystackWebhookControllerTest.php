<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaystackWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        config(['services.paystack.secret_key' => 'test-secret-key']);
    }

    protected function generateSignature(string $payload): string
    {
        return hash_hmac('sha512', $payload, config('services.paystack.secret_key'));
    }

    protected function postWebhook(array $payload): TestResponse
    {
        $content = json_encode($payload);
        $signature = $this->generateSignature($content);

        return $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $content);
    }

    public function test_missing_signature_returns_400(): void
    {
        $response = $this->postJson('/api/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => 'HMS-TEST123'],
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Missing signature.']);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid-signature-here',
        ], json_encode(['event' => 'charge.success', 'data' => ['reference' => 'HMS-TEST123']]));

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid signature.']);
    }

    public function test_valid_charge_success_event_processes_payment(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-PAY-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $paymentTx = PaymentTransaction::create([
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'paystack_reference' => 'HMS-PAY-TEST-001',
            'type' => 'charge',
            'status' => 'pending',
            'amount' => 50000,
            'currency' => 'NGN',
        ]);

        $response = $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'HMS-PAY-TEST-001',
                'amount' => 50000,
                'authorization' => ['authorization_code' => 'AUTH_TEST123'],
                'metadata' => ['folio_id' => $folio->id],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Webhook processed.']);

        $paymentTx->refresh();
        $this->assertEquals('success', $paymentTx->status);
        $this->assertNotNull($paymentTx->paid_at);
    }

    public function test_charge_success_posts_credit_to_folio(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-PAY-002',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        PaymentTransaction::create([
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'paystack_reference' => 'HMS-PAY-TEST-002',
            'type' => 'charge',
            'status' => 'pending',
            'amount' => 30000,
            'currency' => 'NGN',
        ]);

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'HMS-PAY-TEST-002',
                'amount' => 30000,
                'authorization' => ['authorization_code' => 'AUTH_TEST456'],
                'metadata' => ['folio_id' => $folio->id],
            ],
        ]);

        $folio->refresh();
        expect($folio->balance)->toBe(-30000);

        $this->assertDatabaseHas('transactions', [
            'folio_id' => $folio->id,
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 30000,
        ]);
    }

    public function test_unknown_event_type_returns_200(): void
    {
        $response = $this->postWebhook([
            'event' => 'invoice.created',
            'data' => ['reference' => 'HMS-UNKNOWN'],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Webhook processed.']);
    }

    public function test_webhook_with_unknown_reference_returns_200(): void
    {
        $response = $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => 'HMS-NONEXISTENT'],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Webhook processed.']);
    }

    public function test_charge_failed_event_marks_payment_as_failed(): void
    {
        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'folio_number' => 'FOL-PAY-FAIL',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $paymentTx = PaymentTransaction::create([
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'paystack_reference' => 'HMS-PAY-FAIL-001',
            'type' => 'charge',
            'status' => 'pending',
            'amount' => 25000,
            'currency' => 'NGN',
        ]);

        $this->postWebhook([
            'event' => 'charge.failed',
            'data' => [
                'reference' => 'HMS-PAY-FAIL-001',
                'amount' => 25000,
            ],
        ]);

        $paymentTx->refresh();
        $this->assertEquals('failed', $paymentTx->status);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-PAY-IDEM',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        PaymentTransaction::create([
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'paystack_reference' => 'HMS-PAY-IDEM-001',
            'type' => 'charge',
            'status' => 'pending',
            'amount' => 40000,
            'currency' => 'NGN',
        ]);

        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'HMS-PAY-IDEM-001',
                'amount' => 40000,
                'authorization' => ['authorization_code' => 'AUTH_IDEM'],
                'metadata' => ['folio_id' => $folio->id],
            ],
        ];

        // Send the same webhook twice
        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        // Folio balance should be correct (not double-credited)
        $folio->refresh();
        expect($folio->balance)->toBe(-40000);
    }

    public function test_webhook_without_reference_data_returns_200(): void
    {
        $response = $this->postWebhook([
            'event' => 'charge.success',
            'data' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Webhook processed.']);
    }

    public function test_refund_event_creates_refund_transaction(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
        ]);

        $folio = Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-REFUND-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        PaymentTransaction::create([
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'reservation_id' => $reservation->id,
            'paystack_reference' => 'HMS-REFUND-ORIG',
            'type' => 'charge',
            'status' => 'success',
            'amount' => 60000,
            'currency' => 'NGN',
            'paid_at' => now(),
        ]);

        // Paystack refund events reference the original charge reference for lookup
        $this->postWebhook([
            'event' => 'refund.created',
            'data' => [
                'reference' => 'HMS-REFUND-ORIG',
                'amount' => 20000,
                'created_at' => now()->toIso8601String(),
            ],
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'branch_id' => $this->branch->id,
            'folio_id' => $folio->id,
            'type' => 'refund',
            'status' => 'success',
            'amount' => 20000,
        ]);

        $folio->refresh();
        expect($folio->balance)->toBe(20000);
    }
}
