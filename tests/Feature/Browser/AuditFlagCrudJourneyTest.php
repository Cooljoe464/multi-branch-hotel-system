<?php

use App\Models\AuditFlag;
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

    $this->auditor = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'auditor@test.com',
        'password' => 'password',
    ]);
    $this->auditor->assignRole('Auditor');
    $this->auditor->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the audit flags page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/audit/flags')
        ->wait(2)
        ->assertSee('Audit');
});

it('reviews an audit flag via HTTP as global admin', function () {
    $flag = AuditFlag::factory()->create([
        'branch_id' => $this->branch->id,
        'is_reviewed' => false,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/audit/flags/{$flag->id}/review")
        ->assertRedirect();

    $this->assertDatabaseHas('audit_flags', [
        'id' => $flag->id,
        'is_reviewed' => true,
        'reviewed_by' => $this->admin->id,
    ]);
});

it('suppresses an audit flag via HTTP as global admin', function () {
    $flag = AuditFlag::factory()->create([
        'branch_id' => $this->branch->id,
        'is_reviewed' => false,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/audit/flags/{$flag->id}/suppress")
        ->assertRedirect();

    $this->assertDatabaseHas('audit_flags', [
        'id' => $flag->id,
        'is_reviewed' => true,
        'reviewed_by' => $this->admin->id,
    ]);
});

it('reviews an audit flag via HTTP as auditor', function () {
    $flag = AuditFlag::factory()->create([
        'branch_id' => $this->branch->id,
        'is_reviewed' => false,
    ]);

    $this->actingAs($this->auditor)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/audit/flags/{$flag->id}/review")
        ->assertRedirect();

    $this->assertDatabaseHas('audit_flags', [
        'id' => $flag->id,
        'is_reviewed' => true,
    ]);
});

it('shows audit flags list with data', function () {
    AuditFlag::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/audit/flags')
        ->assertOk();
});
