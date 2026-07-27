<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Services\Integrations\OutlookCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();
        $allowedUntil = CarbonImmutable::now()->startOfMonth()->addMonths(3);

        abort_if($start->greaterThan($allowedUntil), 422, 'Calendar can only show up to the next 3 months.');

        $consultations = Consultation::with('type')
            ->whereBetween('starts_at', [$start, $start->endOfMonth()])
            ->when($request->query('application'), fn ($query, $application) => $query->where('application', $application))
            ->when($request->query('consultation_type_id'), fn ($query, $typeId) => $query->where('consultation_type_id', $typeId))
            ->orderBy('starts_at')
            ->get();

        return view('admin.calendar.index', [
            'consultations' => $consultations,
            'selectedMonth' => $start,
            'selectedApplication' => $request->query('application'),
            'selectedConsultationTypeId' => $request->query('consultation_type_id'),
            'consultationTypes' => ConsultationType::query()
                ->when($request->query('application'), fn ($query, $application) => $query->where('application', $application))
                ->orderBy('application')
                ->orderBy('name')
                ->get(),
            'months' => collect(range(0, 3))->map(fn ($offset) => now()->startOfMonth()->addMonths($offset)),
        ]);
    }

    public function sync(OutlookCalendarClient $outlook)
    {
        try {
            $busyCount = $outlook->syncCurrentWindow();
            $futureSync = $outlook->syncFutureConsultations();
        } catch (\DomainException|\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'status',
            "Outlook sync completed. {$busyCount} busy event(s) refreshed, {$futureSync['synced']} future consultation(s) synced, {$futureSync['deleted']} stale consultation event(s) deleted."
        );
    }
}
