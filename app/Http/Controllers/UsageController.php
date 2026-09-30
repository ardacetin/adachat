<?php

namespace App\Http\Controllers;

use App\Domain\Usage\UsageReport;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    public function show(Request $request, UsageReport $report): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('usage', $report->for($user));
    }
}
