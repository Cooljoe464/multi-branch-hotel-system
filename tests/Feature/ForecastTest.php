<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\DemandForecast;
use App\Models\PriceRecommendation;
use App\Models\RateOverride;
use App\Models\RevenueSnapshot;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\BusinessDateService;
use App\Services\Forecasting\Forecaster;
use App\Services\ForecastingService;
use App\Services\PricingRecommender;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000, 'floor_minor' => 8000]);

    foreach (range(1, 10) as $i) {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'number' => "10{$i}",
            'status' => 'available',
            'is_active' => true,
        ]);
    }

    $this->stayDate = Carbon::parse($this->today)->addDays(30)->toDateString();
    $this->forecasting = new ForecastingService;
    $this->pricing = new PricingRecommender;
});

function seedSurge(object $test, int $sold = 9, int $posted = 9): void
{
    RevenueSnapshot::create([
        'branch_id' => $test->branch->id,
        'currency_code' => 'NGN',
        'stay_date' => $test->stayDate,
        'snapshot_date' => $test->today,
        'rooms_available' => 10,
        'rooms_sold' => $sold,
        'room_revenue_minor' => $sold * 10000,
        'total_revenue_minor' => $sold * 10000,
    ]);

    for ($w = 1; $w <= 4; $w++) {
        $day = Carbon::parse($test->stayDate)->subWeeks($w)->toDateString();

        DailyLedger::create([
            'branch_id' => $test->branch->id,
            'business_date' => $day,
            'status' => 'completed',
            'rooms_posted' => $posted,
            'total_room_revenue' => $posted * 10000,
            'total_tax' => 0,
            'total_other_charges' => 0,
            'total_payments' => 0,
            'net_revenue' => $posted * 10000,
        ]);
    }
}

it('recommends an increase within guardrails on a surge', function () {
    seedSurge($this);

    $recommendation = $this->pricing->propose($this->branch, $this->roomType, $this->stayDate);

    expect($recommendation->status)->toBe(PriceRecommendation::STATUS_PROPOSED)
        ->and($recommendation->recommended_minor)->toBe(12000)
        ->and($recommendation->recommended_minor)->toBeLessThanOrEqual((int) round(10000 * 1.25));
});

it('floors the decrease on a crash', function () {
    seedSurge($this, 1, 1);

    $recommendation = $this->pricing->propose($this->branch, $this->roomType, $this->stayDate);

    expect($recommendation->recommended_minor)->toBe(8000);
});

it('never touches live rates without approval', function () {
    seedSurge($this);

    $this->pricing->propose($this->branch, $this->roomType, $this->stayDate);

    expect(RateOverride::forBranch($this->branch->id)->count())->toBe(0)
        ->and($this->roomType->fresh()?->base_rate)->toBe(10000);
});

it('applies once even when double-applied', function () {
    seedSurge($this);

    $recommendation = $this->pricing->propose($this->branch, $this->roomType, $this->stayDate);
    $this->pricing->approve($recommendation, $this->user);

    $first = $this->pricing->apply($recommendation->fresh() ?? $recommendation, $this->user);
    $second = $this->pricing->apply($first->fresh() ?? $first, $this->user);

    expect($first->status)->toBe(PriceRecommendation::STATUS_APPLIED)
        ->and($second->id)->toBe($first->id)
        ->and(RateOverride::forBranch($this->branch->id)
            ->where('room_type_id', $this->roomType->id)
            ->where('rate_override', $first->recommended_minor)
            ->count())->toBe(1);
});

it('falls back to heuristics when the model is down', function () {
    $driver = new class implements Forecaster
    {
        public function forecast(Branch $branch, string $stayDate): array
        {
            throw new RuntimeException('Model sidecar unreachable.');
        }
    };

    $forecast = $this->forecasting->generate($this->branch, $this->stayDate, $driver);

    expect($forecast->model_version)->toBe('heuristic-fallback')
        ->and($forecast->features['fallback'] ?? false)->toBeTrue();
});

it('refuses to apply on an expired forecast', function () {
    seedSurge($this);

    $recommendation = $this->pricing->propose($this->branch, $this->roomType, $this->stayDate);
    $this->pricing->approve($recommendation, $this->user);

    DemandForecast::forBranch($this->branch->id)
        ->where('stay_date', $this->stayDate)
        ->update(['generated_on' => Carbon::parse($this->today)->subDays(3)->toDateString()]);

    try {
        $this->pricing->apply($recommendation->fresh() ?? $recommendation, $this->user);
        $this->fail('Expected a FORECAST_STALE exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('FORECAST_STALE');
    }

    expect($recommendation->fresh()?->status)->toBe(PriceRecommendation::STATUS_EXPIRED)
        ->and(RateOverride::forBranch($this->branch->id)->count())->toBe(0);
});

it('auto-applies inside the band when the branch opts in', function () {
    $this->branch->update(['settings' => ['ai_price_auto_apply' => true]]);

    seedSurge($this, 5, 5);

    $recommendation = $this->pricing->propose($this->branch->fresh() ?? $this->branch, $this->roomType, $this->stayDate);

    expect($recommendation->status)->toBe(PriceRecommendation::STATUS_APPLIED)
        ->and(RateOverride::forBranch($this->branch->id)->count())->toBe(1);
});
