<?php

use App\Models\BankProfile;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();

    $this->admin = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the bank profiles page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/bank-profiles')
        ->wait(2)
        ->assertSee('Bank Profiles')
        ->assertSee('Add Bank Profile');
});

it('creates a bank profile via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/bank-profiles', [
            'bank_name' => 'First Bank',
            'account_number' => '1234567890',
            'account_name' => 'Hotel Main Account',
            'swift_code' => 'FBNINGLA',
            'sort_code' => '011',
            'currency_code' => 'NGN',
            'is_default' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('bank_profiles', [
        'branch_id' => $this->branch->id,
        'bank_name' => 'First Bank',
        'account_number' => '1234567890',
        'account_name' => 'Hotel Main Account',
        'currency_code' => 'NGN',
        'is_default' => true,
    ]);
});

it('updates a bank profile via HTTP as global admin', function () {
    $profile = BankProfile::factory()->create([
        'branch_id' => $this->branch->id,
        'bank_name' => 'Old Bank',
        'account_number' => '0000000000',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/bank-profiles/{$profile->id}", [
            'bank_name' => 'Updated Bank',
            'account_number' => '9999999999',
            'account_name' => 'Updated Account',
            'swift_code' => 'UBANINGLA',
            'sort_code' => '033',
            'currency_code' => 'NGN',
            'is_default' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('bank_profiles', [
        'id' => $profile->id,
        'bank_name' => 'Updated Bank',
        'account_number' => '9999999999',
        'is_default' => false,
    ]);
});

it('denies branch gm access to create bank profiles', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/bank-profiles', [
            'bank_name' => 'GT Bank',
            'account_number' => '1112223334',
            'account_name' => 'GM Account',
            'swift_code' => 'GTBINGLA',
            'sort_code' => '058',
            'currency_code' => 'NGN',
            'is_default' => false,
        ])
        ->assertForbidden();
});

it('shows bank profiles list with data', function () {
    BankProfile::factory()->create([
        'branch_id' => $this->branch->id,
        'bank_name' => 'Listed Bank',
    ]);

    $this->actingAs($this->admin)
        ->get('/bank-profiles')
        ->assertOk();
});
