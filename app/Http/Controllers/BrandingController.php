<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBrandingRequest;
use App\Models\Branding;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(): Response
    {
        $branding = Branding::instance();

        return Inertia::render('settings/Branding', [
            'branding' => [
                'app_name' => $branding->app_name,
                'logo_url' => $branding->logo_url,
            ],
        ]);
    }

    public function update(UpdateBrandingRequest $request): RedirectResponse
    {
        $branding = Branding::instance();

        $branding->update([
            'app_name' => $request->input('app_name'),
        ]);

        if ($request->hasFile('logo')) {
            $branding->setLogo($request->file('logo'));
        }

        return $this->flashSuccess('Branding updated successfully.');
    }

    public function destroyLogo(): RedirectResponse
    {
        $branding = Branding::instance();
        $branding->deleteLogo();

        return $this->flashSuccess('Logo removed successfully.');
    }
}
