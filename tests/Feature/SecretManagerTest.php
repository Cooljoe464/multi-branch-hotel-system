<?php

use App\Models\Branch;
use App\Models\BranchSecret;
use App\Services\Security\SecretManager;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('stores branch secrets encrypted at rest and decrypts them on read', function () {
    $branch = Branch::factory()->create();
    $secrets = new SecretManager;

    $secrets->set($branch, 'firs.api_key', 'live-key-123');

    $stored = BranchSecret::query()->where('branch_id', $branch->id)->firstOrFail();

    expect($stored->value)->not->toContain('live-key-123')
        ->and($secrets->get($branch, 'firs.api_key'))->toBe('live-key-123')
        ->and($secrets->has($branch, 'firs.api_key'))->toBeTrue()
        ->and($secrets->get($branch, 'firs.missing'))->toBeNull();
});

it('keeps secrets isolated per branch for the same key', function () {
    $downtown = Branch::factory()->create();
    $seaside = Branch::factory()->create();
    $secrets = new SecretManager;

    $secrets->set($downtown, 'ocr.api_key', 'downtown-key');
    $secrets->set($seaside, 'ocr.api_key', 'seaside-key');

    expect($secrets->get($downtown, 'ocr.api_key'))->toBe('downtown-key')
        ->and($secrets->get($seaside, 'ocr.api_key'))->toBe('seaside-key');
});

it('rotates a secret only on request and stamps rotated_at', function () {
    $branch = Branch::factory()->create();
    $secrets = new SecretManager;

    $secrets->set($branch, 'xero.access_token', 'token-a');

    expect($secrets->inventory($branch)[0]['rotated_at'])->toBeNull();

    $secrets->rotate($branch, 'xero.access_token', 'token-b');

    $inventory = $secrets->inventory($branch);

    expect($secrets->get($branch, 'xero.access_token'))->toBe('token-b')
        ->and($inventory)->toHaveCount(1)
        ->and($inventory[0]['key'])->toBe('xero.access_token')
        ->and($inventory[0]['rotated_at'])->not->toBeNull();

    $secrets->forget($branch, 'xero.access_token');

    expect($secrets->get($branch, 'xero.access_token'))->toBeNull();
});

it('rejects malformed secret keys', function () {
    $branch = Branch::factory()->create();

    expect(fn () => (new SecretManager)->set($branch, 'bad key!', 'value'))
        ->toThrow(InvalidArgumentException::class);
});

it('reads legacy whatsapp tokens from branch settings when the secret store is empty', function () {
    $branch = Branch::factory()->create([
        'settings' => ['whatsapp' => [
            'enabled' => true,
            'phone_number_id' => '123456789012345',
            'access_token_enc' => 'enc:'.Crypt::encryptString('legacy-token'),
            'app_secret_enc' => 'enc:'.Crypt::encryptString('legacy-secret'),
        ]],
    ]);

    $whatsapp = new WhatsAppCloudApiService;

    expect($whatsapp->accessToken($branch))->toBe('legacy-token')
        ->and($whatsapp->appSecret($branch))->toBe('legacy-secret')
        ->and($whatsapp->verifySignature($branch, 'raw-body', 'sha256='.hash_hmac('sha256', 'raw-body', 'legacy-secret')))->toBeTrue();
});

it('moves whatsapp credentials into the secret store from the settings screen', function () {
    $branch = Branch::factory()->create(['settings' => null]);
    $admin = $this->makeAdminUser($branch);

    $this->actingAs($admin)->put(route('settings.whatsapp.update'), [
        'enabled' => true,
        'phone_number_id' => '123456789012345',
        'waba_id' => '098765432109876',
        'verify_token' => 'verify-me',
        'access_token' => 'new-access-token',
        'app_secret' => 'new-app-secret',
    ])->assertRedirect();

    $branch->refresh();
    $whatsapp = new WhatsAppCloudApiService;

    expect($whatsapp->accessToken($branch))->toBe('new-access-token')
        ->and($whatsapp->appSecret($branch))->toBe('new-app-secret')
        ->and($whatsapp->settings($branch)['access_token_enc'] ?? null)->toBeNull()
        ->and($whatsapp->settings($branch)['verify_token'] ?? null)->toBe('verify-me');

    $rotated = BranchSecret::query()
        ->where('branch_id', $branch->id)
        ->where('key', WhatsAppCloudApiService::SECRET_ACCESS_TOKEN)
        ->firstOrFail();

    expect($rotated->rotated_at)->not->toBeNull();

    $this->actingAs($admin)->put(route('settings.whatsapp.update'), [
        'enabled' => false,
        'clear_access_token' => true,
    ])->assertRedirect();

    expect($whatsapp->accessToken($branch))->toBeNull();
});
