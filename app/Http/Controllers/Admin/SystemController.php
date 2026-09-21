<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function health(): Response
    {
        return Inertia::render('admin/SystemHealth');
    }
}
