<?php

use App\Jobs\ProcessExcelImportJob;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
});

it('dispatches rooms import job with correct type', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'rooms', $this->branch->id, 1);

    expect($job->importType)->toBe('rooms')
        ->and($job->filePath)->toBe('/tmp/test.xlsx')
        ->and($job->branchId)->toBe($this->branch->id)
        ->and($job->userId)->toBe(1);
});

it('dispatches guests import job with correct type', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'guests', $this->branch->id, 1);

    expect($job->importType)->toBe('guests');
});

it('dispatches reservations import job with correct type', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'reservations', $this->branch->id, 1);

    expect($job->importType)->toBe('reservations');
});

it('throws on unknown import type', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'unknown', $this->branch->id, 1);
    $job->handle();
})->throws(InvalidArgumentException::class, 'Unknown import type: unknown');

it('uses imports queue', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'rooms', $this->branch->id, 1);

    expect($job->queue)->toBe('imports');
});

it('has correct timeout and tries', function () {
    $job = new ProcessExcelImportJob('/tmp/test.xlsx', 'rooms', $this->branch->id, 1);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(300);
});

it('imports rooms from excel file', function () {
    RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'DLX',
        'base_rate' => 15000,
    ]);

    $csvContent = "number,room_type_code,floor,wing,status,is_accessible,is_smoking\n".
        "101,DLX,1,north,available,no,no\n".
        "102,DLX,1,north,available,yes,no\n";

    $filePath = storage_path('app/test-rooms-import.csv');
    file_put_contents($filePath, $csvContent);

    $job = new ProcessExcelImportJob($filePath, 'rooms', $this->branch->id, 1);
    $result = $job->handle();

    expect($result['created'])->toBe(2)
        ->and($result['skipped'])->toBe(0);

    $this->assertDatabaseHas(Room::class, [
        'branch_id' => $this->branch->id,
        'number' => '101',
    ]);

    $this->assertDatabaseHas(Room::class, [
        'branch_id' => $this->branch->id,
        'number' => '102',
    ]);

    @unlink($filePath);
});

it('imports guests from excel file', function () {
    $csvContent = "first_name,last_name,email,phone,vip_status\n".
        "John,Doe,john@example.com,1234567890,none\n".
        "Jane,Smith,jane@example.com,0987654321,silver\n";

    $filePath = storage_path('app/test-guests-import.csv');
    file_put_contents($filePath, $csvContent);

    $job = new ProcessExcelImportJob($filePath, 'guests', $this->branch->id, 1);
    $result = $job->handle();

    expect($result['created'])->toBe(2)
        ->and($result['skipped'])->toBe(0);

    $this->assertDatabaseHas(Guest::class, ['email' => 'john@example.com']);
    $this->assertDatabaseHas(Guest::class, ['email' => 'jane@example.com']);

    @unlink($filePath);
});

it('skips duplicate guest emails', function () {
    Guest::factory()->create(['email' => 'existing@example.com']);

    $csvContent = "first_name,last_name,email,phone,vip_status\n".
        "John,Doe,existing@example.com,1234567890,none\n";

    $filePath = storage_path('app/test-guests-dup.csv');
    file_put_contents($filePath, $csvContent);

    $job = new ProcessExcelImportJob($filePath, 'guests', $this->branch->id, 1);
    $result = $job->handle();

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(1);

    @unlink($filePath);
});
