<?php

// Single contested-booking attempt, executed N times concurrently by
// AvailabilityLoadTest via `php artisan test --group=load-worker`.
// Not a standalone test: it needs the parent's committed LOAD-* seed.

use App\Models\Branch;
use App\Models\User;

uses()->group('load-worker');

it('attempts one contested booking', function () {
    $dir = (string) getenv('LOAD_RESULT_DIR');
    $tag = (string) getenv('LOAD_WORKER');
    $beat = fn (string $step) => file_put_contents("{$dir}/{$tag}.beat", $step."\n", FILE_APPEND);

    $beat('boot');
    $branch = Branch::where('code', 'LOAD-01')->firstOrFail();
    $beat('branch');
    $user = User::where('email', 'load.runner@example.com')->firstOrFail();
    $beat('user');

    $response = $this->actingAs($user)->post('/reservations', [
        'room_type_id' => $branch->roomTypes()->firstOrFail()->id,
        'room_id' => $branch->rooms()->firstOrFail()->id,
        'guest_name' => 'Load Racer '.getenv('LOAD_WORKER'),
        'guest_email' => 'racer'.getenv('LOAD_WORKER').'@example.com',
        'adults' => 2,
        'children' => 0,
        'check_in_date' => now()->addDays(10)->format('Y-m-d'),
        'check_out_date' => now()->addDays(12)->format('Y-m-d'),
    ]);
    $beat('posted');

    // Success redirects to /reservations/{id}; rejections redirect back.
    $location = (string) $response->headers->get('Location');
    $outcome = preg_match('#/reservations/(\d+)$#', $location, $m)
        ? "WON:{$m[1]}"
        : "LOST:{$response->status()}";
    $beat('parsed');

    file_put_contents($dir.'/'.getenv('LOAD_WORKER').'.txt', $outcome);
    $beat('written');
});
