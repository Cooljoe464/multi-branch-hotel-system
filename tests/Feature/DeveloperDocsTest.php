<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id, 'is_global_admin' => true]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->frontDesk = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->frontDesk->assignRole('Front Desk');
    $this->frontDesk->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('renders the developer docs page with the assembled OpenAPI spec', function () {
    $this->withoutVite();

    $response = $this->actingAs($this->admin)->get('/developer/docs');

    $response->assertOk();
    $page = $response->inertiaPage();

    expect($page['component'])->toBe('developers/Docs');
    expect($page['props']['spec'])->toBeArray();
    expect($page['props']['spec']['openapi'])->toBe('3.1.0');
});

it('denies the developer docs page without the api.view permission', function () {
    $this->actingAs($this->frontDesk)->get('/developer/docs')->assertForbidden();
});

it('generates the TypeScript SDK client from the OpenAPI document', function () {
    $directory = sys_get_temp_dir().'/hospitalityos-sdk-test';

    if (File::exists($directory)) {
        File::deleteDirectory($directory);
    }
    File::makeDirectory($directory, 0755, true, true);

    $exit = Artisan::call('openapi:sdk', ['--dir' => $directory]);

    expect($exit)->toBe(0);
    expect(File::exists($directory.'/client.ts'))->toBeTrue();
    expect(File::exists($directory.'/package.json'))->toBeTrue();

    File::deleteDirectory($directory);
});
