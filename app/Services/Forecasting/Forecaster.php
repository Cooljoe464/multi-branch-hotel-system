<?php

namespace App\Services\Forecasting;

use App\Models\Branch;

interface Forecaster
{
    /**
     * @return array{p_demand: float, expected_rooms: int, features: array<string, mixed>, model_version: string}
     */
    public function forecast(Branch $branch, string $stayDate): array;
}
