<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\PriceRecommendation;
use App\Services\PricingRecommender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PriceRecommendationController extends Controller
{
    use EnsuresBranchAccess;

    public function approve(Branch $branch, PriceRecommendation $recommendation, Request $request, PricingRecommender $recommender): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($recommendation->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $recommender->approve($recommendation, $user);

        return redirect()->route('forecast.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Proposal #{$recommendation->id} approved."]);
    }

    public function reject(Branch $branch, PriceRecommendation $recommendation, Request $request, PricingRecommender $recommender): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($recommendation->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $recommender->reject($recommendation, $user);

        return redirect()->route('forecast.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Proposal #{$recommendation->id} rejected."]);
    }

    public function apply(Branch $branch, PriceRecommendation $recommendation, Request $request, PricingRecommender $recommender): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($recommendation->branch_id === $branch->id, 404);

        $recommender->apply($recommendation, $request->user());

        return redirect()->route('forecast.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Proposal #{$recommendation->id} applied to the rate plan."]);
    }
}
