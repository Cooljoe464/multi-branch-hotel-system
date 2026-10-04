<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Failed Jobs Threshold
    |--------------------------------------------------------------------------
    |
    | The /readyz probe degrades when failed_jobs reaches this count.
    | Tune per environment; the queue dashboard stays authoritative.
    |
    */

    'failed_jobs_threshold' => (int) env('HEALTH_FAILED_JOBS_THRESHOLD', 100),

];
