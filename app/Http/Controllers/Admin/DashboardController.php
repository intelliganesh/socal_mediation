<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\PaymentRequest;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('admin.dashboard', [
            'totals' => [
                'consultations' => Consultation::count(),
                'drafts' => Consultation::where('status', 'draft')->count(),
                'scheduled' => Consultation::whereNotNull('starts_at')->count(),
                'revenue_cents' => PaymentRequest::where('status', 'paid')->sum('amount_cents'),
            ],
            'applicationCounts' => [
                'socal' => Consultation::where('application', 'socal')->count(),
                'legal' => Consultation::where('application', 'legal')->count(),
            ],
            'recent' => Consultation::with(['type', 'paymentRequests'])
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}
