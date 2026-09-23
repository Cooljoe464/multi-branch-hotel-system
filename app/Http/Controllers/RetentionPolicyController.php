<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\RetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetentionPolicyController extends Controller
{
    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'data_class' => 'required|string|max:64',
            'retain_days' => 'required|integer|min:1|max:36500',
            'action' => 'required|string|in:anonymize,purge',
        ]);

        if ($request->string('data_class')->value() === 'folio_lines') {
            return back()->withErrors(['data_class' => 'Folio lines are a legal hold and cannot be purged.']);
        }

        RetentionPolicy::updateOrCreate(
            ['data_class' => $request->string('data_class')->value()],
            [
                'retain_days' => $request->integer('retain_days'),
                'action' => $request->string('action')->value(),
            ],
        );

        return $this->flashSuccess('Retention policy saved.');
    }
}
