<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\PaymentRequest;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $consultations = $user->applyApplicationScope(Consultation::query());
        $paymentRequests = PaymentRequest::query()
            ->where('status', 'paid')
            ->whereHas('consultation', fn ($query) => $user->applyApplicationScope($query));
        $paymentStatuses = ['paid', 'pending', 'partially_paid'];
        $paymentStatusCounts = (clone $consultations)
            ->whereIn('payment_status', $paymentStatuses)
            ->selectRaw('payment_status, count(*) as aggregate')
            ->groupBy('payment_status')
            ->pluck('aggregate', 'payment_status');
        $paymentMixTotal = collect($paymentStatuses)->sum(fn ($status) => (int) ($paymentStatusCounts[$status] ?? 0));

        return view('admin.dashboard', [
            'totals' => [
                'consultations' => (clone $consultations)->count(),
                'drafts' => (clone $consultations)->where('status', 'draft')->count(),
                'scheduled' => (clone $consultations)->where('status', 'scheduled')->count(),
                'revenue_cents' => (clone $paymentRequests)->sum('amount_cents'),
            ],
            'applications' => $user->allowedApplications(),
            'applicationCounts' => collect($user->allowedApplications())
                ->mapWithKeys(fn ($application) => [
                    $application => Consultation::where('application', $application)->count(),
                ])
                ->all(),
            'applicationRevenue' => collect($user->allowedApplications())
                ->mapWithKeys(fn ($application) => [
                    $application => PaymentRequest::where('status', 'paid')
                        ->whereHas('consultation', fn ($query) => $query->where('application', $application))
                        ->sum('amount_cents'),
                ])
                ->all(),
            'recent' => $user->applyApplicationScope(Consultation::with(['type', 'paymentRequests']))
                ->latest()
                ->limit(8)
                ->get(),
            'paymentMix' => collect($paymentStatuses)
                ->mapWithKeys(fn ($status) => [
                    $status => [
                        'count' => (int) ($paymentStatusCounts[$status] ?? 0),
                        'percent' => $paymentMixTotal > 0 ? round(((int) ($paymentStatusCounts[$status] ?? 0) / $paymentMixTotal) * 100) : 0,
                    ],
                ])
                ->all(),
            'paymentMixTotal' => $paymentMixTotal,
        ]);
    }
}
