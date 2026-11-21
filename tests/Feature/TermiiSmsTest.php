<?php

use App\Models\Branch;
use App\Services\Security\SecretManager;
use App\Services\Sms\SmsService;
use App\Services\Sms\TermiiSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['name' => 'Wheatbaker Ikoyi']);
    $this->secrets = app(SecretManager::class);
    $this->driver = new TermiiSmsDriver($this->secrets);
    $this->service = new SmsService($this->driver);
});

it('formats Nigerian local mobile numbers to international 234 format', function () {
    expect($this->driver->formatPhoneNumber('08031234567'))->toBe('2348031234567')
        ->and($this->driver->formatPhoneNumber('07019876543'))->toBe('2347019876543')
        ->and($this->driver->formatPhoneNumber('2348123456789'))->toBe('2348123456789');
});

it('dispatches transactional OTP via Termii DND route', function () {
    $this->secrets->set($this->branch, TermiiSmsDriver::SECRET_API_KEY, 'termii-secret-key');
    $this->secrets->set($this->branch, TermiiSmsDriver::SECRET_SENDER_ID, 'Wheatbaker');

    Http::fake([
        'https://api.ng.termii.com/api/sms/send' => function ($request) {
            $data = $request->data();
            expect($data['to'])->toBe('2348031234567')
                ->and($data['from'])->toBe('Wheatbaker')
                ->and($data['channel'])->toBe('dnd')
                ->and($data['sms'])->toContain('verification code is: 4892');

            return Http::response([
                'message_id' => 'MSG-TERMII-00123',
                'message' => 'Successfully Sent',
                'balance' => 450.50,
            ], 200);
        },
    ]);

    $res = $this->service->sendOtp('08031234567', '4892', $this->branch);

    expect($res['ok'])->toBeTrue()
        ->and($res['message_id'])->toBe('MSG-TERMII-00123')
        ->and($res['balance'])->toBe(450.50);
});

it('dispatches room check-in passcode SMS to guest', function () {
    $this->secrets->set($this->branch, TermiiSmsDriver::SECRET_API_KEY, 'termii-secret-key');

    Http::fake([
        'https://api.ng.termii.com/api/sms/send' => function ($request) {
            $data = $request->data();
            expect($data['sms'])->toContain('Welcome to Wheatbaker Ikoyi, Chioma!')
                ->and($data['sms'])->toContain('Your room is 204')
                ->and($data['sms'])->toContain('PIN is 849201');

            return Http::response([
                'message_id' => 'MSG-DOOR-PIN-1',
                'message' => 'Successfully Sent',
            ], 200);
        },
    ]);

    $res = $this->service->sendCheckinPasscode('08031234567', 'Chioma', '204', '849201', $this->branch);

    expect($res['ok'])->toBeTrue()
        ->and($res['message_id'])->toBe('MSG-DOOR-PIN-1');
});

it('checks Termii wallet balance', function () {
    $this->secrets->set($this->branch, TermiiSmsDriver::SECRET_API_KEY, 'termii-secret-key');

    Http::fake([
        'https://api.ng.termii.com/api/get-balance*' => Http::response([
            'balance' => 12500.00,
            'currency' => 'NGN',
            'user' => 'hotel_admin',
        ], 200),
    ]);

    $balance = $this->service->checkBalance($this->branch);

    expect($balance['ok'])->toBeTrue()
        ->and($balance['balance'])->toBe(12500.00)
        ->and($balance['currency'])->toBe('NGN');
});

it('receives Termii delivery status reports (DSR) webhook', function () {
    $this->postJson(route('api.webhooks.termii.dsr'), [
        'message_id' => 'MSG-TERMII-00123',
        'status' => 'delivered',
        'receiver' => '2348031234567',
    ])
        ->assertOk()
        ->assertJson([
            'status' => 'acknowledged',
            'message_id' => 'MSG-TERMII-00123',
        ]);
});
