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
                'scheduled' => Consultation::where('status', 'scheduled')->count(),
                'revenue_cents' => PaymentRequest::where('status', 'paid')->sum('amount_cents'),
            ],
            'applicationCounts' => [
                'socal' => Consultation::where('application', 'socal')->count(),
                'legal' => Consultation::where('application', 'legal')->count(),
            ],
            'applicationRevenue' => [
                'socal' => PaymentRequest::where('status', 'paid')
                    ->whereHas('consultation', fn ($query) => $query->where('application', 'socal'))
                    ->sum('amount_cents'),
                'legal' => PaymentRequest::where('status', 'paid')
                    ->whereHas('consultation', fn ($query) => $query->where('application', 'legal'))
                    ->sum('amount_cents'),
            ],
            'recent' => Consultation::with(['type', 'paymentRequests'])
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }
}
