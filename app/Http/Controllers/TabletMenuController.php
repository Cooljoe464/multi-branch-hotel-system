<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\TabletSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TabletMenuController extends Controller
{
    public function index(Request $request): Response
    {
        $session = $this->getSession($request);

        $menuItems = MenuItem::forBranch($session->branch_id)
            ->active()
            ->available()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        return Inertia::render('tablet/Menu', [
            'menuItems' => $menuItems,
            'session' => $session,
        ]);
    }

    protected function getSession(Request $request): TabletSession
    {
        $confirmationNumber = $request->route('confirmationNumber');

        return TabletSession::where('confirmation_number', $confirmationNumber)
            ->whereNull('wiped_at')
            ->firstOrFail();
    }
}
