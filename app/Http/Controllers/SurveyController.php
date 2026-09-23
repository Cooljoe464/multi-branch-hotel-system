<?php

namespace App\Http\Controllers;

use App\Events\SurveyReceived;
use App\Models\PostStaySurvey;
use App\Models\Reservation;
use App\Services\SentimentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SurveyController extends Controller
{
    public function show(Reservation $reservation): Response
    {
        $survey = PostStaySurvey::firstOrCreate(['reservation_id' => $reservation->id]);

        return Inertia::render('surveys/Show', [
            'reservation' => $reservation->only(['id', 'confirmation_number', 'guest_name']),
            'survey' => $survey,
        ]);
    }

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        $request->validate([
            'nps' => 'nullable|integer|min:0|max:10',
            'answers' => 'nullable|array|max:50',
        ]);

        $answers = $request->input('answers');
        $clean = [];
        if (is_array($answers)) {
            foreach ($answers as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value))) {
                    $clean[$key] = $value;
                }
            }
        }

        $text = implode(' ', array_filter(array_map(
            fn ($value) => is_string($value) ? $value : '',
            array_values($clean)
        )));

        $nps = $request->input('nps');
        $nps = is_int($nps) ? $nps : null;

        // Re-submits update the same row; sentiment re-scores.
        $survey = PostStaySurvey::updateOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'nps' => $nps,
                'answers' => $clean === [] ? null : $clean,
                'sentiment' => (new SentimentService)->score($text, $nps),
            ],
        );

        event(new SurveyReceived($survey->fresh() ?? $survey));

        return $this->flashSuccess('Thanks — your feedback was recorded.');
    }
}
