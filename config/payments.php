<?php

use App\Services\Payments\PaystackDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | Per-branch override lives in branches.settings -> payment_driver.
    | Additional drivers register here and in PaymentService::forBranch().
    |
    */

    'default' => env('PAYMENT_DRIVER', 'paystack'),

    'drivers' => [
        'paystack' => PaystackDriver::class,
    ],

];
