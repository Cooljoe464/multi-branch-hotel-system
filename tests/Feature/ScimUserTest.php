<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
});

function scimConsumer(Branch $branch, array $scopes): ApiConsumer
{
    return ApiConsumer::create([
        'name' => 'SCIM Directory Consumer',
        'branch_id' => $branch->id,
        'branch_ids' => null,
        'scopes' => $scopes,
        'is_active' => true,
    ]);
}

function scimBearer(ApiConsumer $consumer): string
{
    return $consumer->createToken('scim-token', $consumer->tokenAbilities(), now()->addYear())->plainTextToken;
}

it('rejects scim directory requests without a bearer token', function () {
    $this->getJson('/api/scim/v2/Users')->assertUnauthorized();
});

it('provisions a scim user from the directory', function () {
    $consumer = scimConsumer($this->branch, ['scim.manage']);
    $token = scimBearer($consumer);
    $subject = (string) Str::uuid();

    $response = $this->withToken($token)->postJson('/api/scim/v2/Users', [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id' => $subject,
        'userName' => 'directory.user@example.com',
        'name' => ['givenName' => 'Dir', 'familyName' => 'User'],
        'active' => true,
    ]);

    $response->assertCreated();
    expect($response->json('userName'))->toBe('directory.user@example.com');

    $user = User::query()->where('sso_issuer', 'scim')->where('sso_subject', $subject)->first();

    expect($user)->not->toBeNull()
        ->and($user?->email)->toBe('directory.user@example.com');
});

it('lists scim provisioned users for the consumer tenant', function () {
    $consumer = scimConsumer($this->branch, ['scim.manage']);
    $token = scimBearer($consumer);

    $response = $this->withToken($token)->getJson('/api/scim/v2/Users');

    $response->assertOk();
});

it('revokes a scim provisioned user on delete', function () {
    $consumer = scimConsumer($this->branch, ['scim.manage']);
    $token = scimBearer($consumer);
    $subject = (string) Str::uuid();

    $created = $this->withToken($token)->postJson('/api/scim/v2/Users', [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id' => $subject,
        'userName' => 'directory.user@example.com',
        'name' => ['givenName' => 'Dir', 'familyName' => 'User'],
        'active' => true,
    ]);

    $created->assertCreated();
    $user = User::query()->where('sso_issuer', 'scim')->where('sso_subject', $subject)->first();

    expect($user)->not->toBeNull();

    $this->withToken($token)->deleteJson('/api/scim/v2/Users/'.$user->id)->assertOk();

    expect(User::query()->where('id', $user->id)->exists())->toBeFalse();
});
