<x-admin.layout heading="" subheading="">
    @php
    $app = $consultation->application === 'legal'
    ? ['label' => 'Legal Consultation', 'icon' => 'scale', 'theme' => 'app-theme-legal', 'iconClass' => 'app-icon-legal', 'textClass' => 'app-text-legal', 'progress' => 'app-progress-legal']
    : ['label' => 'SoCal Mediation Center', 'icon' => 'landmark', 'theme' => 'app-theme-socal', 'iconClass' => 'app-icon-socal', 'textClass' => 'app-text-socal', 'progress' => 'app-progress-socal'];
    $statusTheme = function (?string $status) {
    return match ($status) {
    'paid' => ['badge' => 'status-badge-paid', 'progress' => 'progress-fill-paid', 'label' => 'Paid'],
    'scheduled' => ['badge' => 'status-badge-scheduled', 'progress' => 'progress-fill-scheduled', 'label' => 'Scheduled'],
    'cancelled' => ['badge' => 'status-badge-cancelled', 'progress' => 'progress-fill-cancelled', 'label' => 'Cancelled'],
    'completed' => ['badge' => 'status-badge-completed', 'progress' => 'progress-fill-completed', 'label' => 'Completed'],
    'rescheduled' => ['badge' => 'status-badge-rescheduled', 'progress' => 'progress-fill-scheduled', 'label' => 'Rescheduled'],
    'in_progress' => ['badge' => 'status-badge-in-progress', 'progress' => 'progress-fill-scheduled', 'label' => 'In Progress'],
    'overdue' => ['badge' => 'status-badge-overdue', 'progress' => 'progress-fill-overdue', 'label' => 'Overdue'],
    'partially_paid' => ['badge' => 'status-badge-partially-paid', 'progress' => 'progress-fill-partially-paid', 'label' => 'Partially Paid'],
    'pending', 'payment_pending' => ['badge' => 'status-badge-pending', 'progress' => 'progress-fill-pending', 'label' => $status === 'payment_pending' ? 'Payment Pending' : 'Pending'],
    default => ['badge' => 'status-badge-draft', 'progress' => 'progress-fill-draft', 'label' => str_replace('_', ' ', ucfirst((string) $status ?: 'Draft'))],
    };
    };
    $bookingStatus = $statusTheme($consultation->status);
    $paymentStatus = $statusTheme($consultation->payment_status);
    $paidPaymentCount = $consultation->paymentRequests->where('status', 'paid')->count();
    $totalPaymentCount = $consultation->paymentRequests->count();
    $pendingPaymentCount = max(0, $totalPaymentCount - $paidPaymentCount);
    $collectedAmountCents = $consultation->paymentRequests->where('status', 'paid')->sum('amount_cents');
    $progressPercent = $totalPaymentCount ? round(($paidPaymentCount / $totalPaymentCount) * 100) : 0;
    $unpaidPaymentCount = $consultation->paymentRequests->filter(fn ($payment) => $payment->status !== 'paid')->count();
    $emailActivities = $consultation->integrationLogs->where('provider', 'mail')->sortByDesc('created_at')->values();
    $emailActivityMeta = function (string $action) {
    return match ($action) {
    'manual_payment_reminder' => ['label' => 'Payment Reminder', 'icon' => 'bell'],
    'manual_payment_link' => ['label' => 'Payment Link', 'icon' => 'credit-card'],
    'automatic_payment_link' => ['label' => 'Payment Link', 'icon' => 'credit-card'],
    'manual_zoom_link' => ['label' => 'Zoom Link', 'icon' => 'video'],
    'manual_reschedule_zoom_link' => ['label' => 'Reschedule Zoom Link', 'icon' => 'refresh-cw'],
    default => ['label' => Str::headline($action), 'icon' => 'mail'],
    };
    };
    $outlookSync = $consultation->integrationLogs->where('provider', 'outlook')->sortByDesc('created_at')->first();
    $outlookStatus = $outlookSync ? str_replace('_', ' ', ucfirst($outlookSync->status)) : ($consultation->starts_at ? 'Sync Pending' : 'Not Scheduled');
    $zoomStatus = filled($consultation->zoom_join_url) ? 'Generated' : ($consultation->consultation_mode === 'online' ? 'Pending' : 'Not Required');
    $primaryName = trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet';
    $consultationStatusOptions = ['draft', 'pending', 'payment_pending', 'paid', 'scheduled', 'rescheduled', 'in_progress', 'completed', 'cancelled', 'overdue'];
    $paymentStatusOptions = ['pending', 'partially_paid', 'paid', 'failed', 'refunded'];
    @endphp
    <div class="-mt-1 mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-[#111827]">Consultation {{ $consultation->booking_number }}</h2>
            <div class="mt-2 flex flex-wrap items-center gap-3 text-sm font-semibold text-gray-500">
                <span>{{ $consultation->type->name }} - {{ $app['label'] }}</span>
                <span class="status-badge {{ $bookingStatus['badge'] }}">{{ $bookingStatus['label'] }}</span>
                <span class="inline-flex items-center gap-1"><i data-lucide="calendar-days" class="h-4 w-4"></i>{{ $consultation->starts_at?->format('M d, Y g:i A') ?? 'Not scheduled' }}</span>
                <span class="inline-flex items-center gap-1"><i data-lucide="user" class="h-4 w-4"></i>{{ $consultation->professional?->name ?: 'Not assigned' }}</span>
            </div>
        </div>
        <a class="inline-flex h-11 items-center gap-2 rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index') }}">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>
            Back to list
        </a>
    </div>
    <section class="mb-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <h3 class="sr-only">Admin Actions</h3>
        <span class="sr-only">Regenerate Meeting Link</span>
        @if($unpaidPaymentCount > 0)
        <span class="sr-only">Send Payment Links</span>
        <form class="action-card-primary min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.reminder', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="bell" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Send Reminder</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send Payment Reminder for unpaid participants.</p>
            <button class="action-card-button mt-5 h-10 w-full rounded-lg text-sm font-bold">Send Reminder</button>
        </form>
        @endif
        <form class="action-card-primary min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.zoom-link', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="video" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Send Zoom Links</h3>
            <span class="sr-only">Resend Zoom Link</span>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send Zoom meeting links to all the participants.</p>
            <div class="mt-5 grid gap-2">
                <button class="action-card-button h-10 w-full rounded-lg text-sm font-bold">Send Zoom Link</button>
                @if(filled($consultation->zoom_join_url))
                <a class="flex h-10 items-center justify-center gap-2 rounded-lg border border-[#E5E7EB] bg-white text-sm font-bold text-[#082BC3]" href="{{ $consultation->zoom_join_url }}" target="_blank" rel="noopener">
                    Open Zoom Meeting Link
                    <i data-lucide="external-link" class="h-4 w-4"></i>
                </a>
                @endif
            </div>
        </form>
        @if($consultation->starts_at)
        <form class="action-card-pending min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.reschedule', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="refresh-cw" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Reschedule Meeting</h3>
            <p class="mt-2 text-sm font-semibold text-gray-500">Reschedule meeting and send new meeting link to all participants.</p>
            <input class="mt-3 h-9 w-full min-w-0 rounded-lg border border-[#E5E7EB] px-3 text-xs font-bold text-[#111827]" type="datetime-local" name="starts_at" value="{{ old('starts_at', $consultation->starts_at->format('Y-m-d\\TH:i')) }}" required>
            <button class="action-card-button mt-3 h-10 w-full rounded-lg text-sm font-bold">Reschedule</button>
        </form>
        @endif
        <form class="action-card-paid min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.sync-outlook', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="calendar-plus" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Sync This Booking To Outlook</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Sync Consultation to outlook.</p>
            <button class="action-card-button mt-5 h-10 w-full rounded-lg text-sm font-bold">Sync to Outlook</button>
        </form>
        @if(! in_array($consultation->status, ['cancelled', 'failed'], true))
        <form class="action-card-cancelled min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.cancel', $consultation) }}" onsubmit="return confirm('Cancel this consultation?');">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="x" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Cancel Consultation</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Cancel Consultation.</p>
            <button class="action-card-button mt-5 h-10 w-full rounded-lg text-sm font-bold">Cancel Consultation</button>
        </form>
        @endif
    </section>
    <section class="mb-5 grid gap-5 xl:grid-cols-3">
        <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="flex items-center justify-between border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="circle-user-round" class="h-5 w-5 text-[#082BC3]"></i>Primary Client Information</h3>
            </div>
            <div class="flex items-center gap-5 p-5">
                <div class="grid h-24 w-24 shrink-0 place-items-center rounded-full text-4xl font-semibold {{ $app['iconClass'] }}">{{ Str::upper(Str::substr($primaryName, 0, 2)) }}</div>
                <div class="min-w-0 text-sm">
                    <div class="text-xl font-bold text-[#111827]">{{ $primaryName }}</div>
                    <div class="mt-2 truncate font-semibold text-[#082BC3]">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                    <div class="mt-3 text-[#111827]">{{ trim(($consultation->primary_phone_country ?? '').' '.($consultation->primary_phone ?? '')) ?: 'No phone yet' }}</div>
                    {{-- <span class="mt-4 inline-flex rounded-lg bg-[#F3F4F6] px-3 py-2 text-sm font-semibold text-gray-500">Client</span> --}}
                </div>
            </div>
        </article>
        <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="flex items-center justify-between border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="info" class="h-5 w-5 text-[#082BC3]"></i>Consultation Information</h3>
            </div>
            <dl class="grid gap-5 p-5 text-sm sm:grid-cols-2">
                <div class="flex gap-3"><i data-lucide="calendar-days" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Schedule</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->starts_at?->format('M d, Y g:i A') ?? 'Not scheduled' }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="video" class="h-5 w-5 text-gray-500"></i>
                    <div>
                        <dt class="text-gray-500">Zoom Meeting</dt>
                        <dd class="mt-1 flex font-semibold text-[#111827]">{{ $zoomStatus }} @if(filled($consultation->zoom_join_url)) <a class="font-bold text-[#082BC3] ml-2" href="{{ $consultation->zoom_join_url }}" target="_blank" rel="noopener"><i data-lucide="external-link" class="h-4 w-4"></i></a> @endif</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="clock" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Consultation Type</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->type->name }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="calendar-check" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Outlook Event</dt>
                        <dd class="mt-1 font-semibold text-[#082BC3]">{{ $outlookStatus }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="scale" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Legal Service</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->legal_service_name ?: 'Not selected' }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="monitor" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Meeting Mode</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ ucfirst((string) $consultation->consultation_mode) ?: 'Not selected' }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="network" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Referral Source</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->referral_source ?: 'Not provided' }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="calendar" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Created</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->created_at?->format('M d, Y g:i A') }}</dd>
                    </div>
                </div>
                <div class="sr-only">Outlook Calendar</div>
                <div class="sr-only">Zoom</div>
                <div class="sr-only">Professional {{ $consultation->professional?->name ?: 'Not assigned' }}</div>
            </dl>
        </article>
        <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="sliders-horizontal" class="h-5 w-5 text-[#082BC3]"></i>Status Controls</h3>
            </div>
            <form class="action-card-primary grid gap-4 p-5 text-sm" method="post" action="{{ route('admin.consultations.statuses', $consultation) }}">
                @csrf
                <label class="grid gap-2 font-bold text-[#111827]">
                    Consultation Status
                    <select class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" name="status" required>
                        @foreach($consultationStatusOptions as $status)
                        <option value="{{ $status }}" @selected(old('status', $consultation->status) === $status)>{{ Str::headline($status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-2 font-bold text-[#111827]">
                    Payment Status
                    <select class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" name="payment_status" required>
                        @foreach($paymentStatusOptions as $status)
                        <option value="{{ $status }}" @selected(old('payment_status', $consultation->payment_status) === $status)>{{ Str::headline($status) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="action-card-button h-10 w-full rounded-lg text-sm font-bold" type="submit">Update Statuses</button>
            </form>
        </article>
    </section>
    <div class="grid gap-5 xl:grid-cols-3 mb-5">
        <div class="space-y-5 xl:col-span-2">
            <section class="rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
                <h3 class="mb-4 font-bold text-[#111827]">Participants</h3>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach($consultation->participants as $participant)
                    @php($participantName = trim($participant->first_name.' '.$participant->last_name))
                    @php($participantPayment = $consultation->paymentRequests->firstWhere('participant_id', $participant->id))
                    @php($participantStatus = $statusTheme($participantPayment?->status))
                    <article class="rounded-lg border border-[#E5E7EB] p-3 text-sm">
                        <div class="flex items-start gap-3">
                            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[#ECEDF9] text-xs font-bold text-[#082BC3]">{{ Str::upper(Str::substr($participantName, 0, 2)) }}</div>
                            <div class="min-w-0">
                                <div class="truncate font-bold text-[#111827]">{{ $participantName }}</div>
                                <div class="truncate text-xs text-gray-500">{{ $participant->email ?: 'Not provided' }}</div>
                                <div class="mt-2"><span class="status-badge {{ $participantStatus['badge'] }}">{{ $participantStatus['label'] }}</span></div>
                            </div>
                        </div>
                    </article>
                    @endforeach
                </div>
            </section>
        </div>
        <section class="overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <article class="">
                <div class="flex items-center justify-between border-b border-[#E5E7EB] px-5 py-4">
                    <h3 class="font-bold text-[#111827]">Payment Progress</h3>
                    <span class="status-badge {{ $paymentStatus['badge'] }}">{{ $paymentStatus['label'] }}</span>
                </div>
                <div class="p-5">
                    <div class="mb-3 flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-500">{{ $totalPaymentCount }} {{ Str::plural('participant', $totalPaymentCount) }}</span>
                        <span class="font-bold text-gray-500">{{ $paidPaymentCount }} Paid · {{ $pendingPaymentCount }} Pending</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill {{ $paymentStatus['progress'] }}" style="width: {{ $progressPercent }}%"></div>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-500">${{ number_format($collectedAmountCents / 100, 2) }} collected</span>
                        <span class="text-gray-500">${{ number_format($consultation->total_amount_cents / 100, 2) }} total</span>
                    </div>
                    <div class="sr-only">{{ $totalPaymentCount }} participants - {{ $paidPaymentCount }} Paid - {{ $pendingPaymentCount }} Pending</div>
                </div>
            </article>
        </section>
    </div>
    <div class="grid gap-5 xl:grid-cols-3 ">
        <div class="space-y-5 xl:col-span-2">
            <section class="rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
                <h3 class="mb-4 font-bold text-[#111827]">Email Activity</h3>
                <div class="space-y-3">
                    @forelse($emailActivities as $activity)
                    @php($activityStatus = $statusTheme($activity->status))
                    @php($activityMeta = $emailActivityMeta($activity->action))
                    <article class="flex items-center gap-3 text-sm">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[#082BC3] text-white"><i data-lucide="{{ $activityMeta['icon'] }}" class="h-5 w-5"></i></div>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-[#111827]">{{ $activityMeta['label'] }}</div>
                            <div class="truncate text-xs text-gray-500">To: {{ data_get($activity->request_payload, 'recipient', 'Not recorded') }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-gray-500">{{ $activity->created_at?->format('M d, Y g:i A') }}</div>
                            <span class="status-badge {{ $activityStatus['badge'] }} mt-1">{{ ucfirst($activity->status) }}</span>
                        </div>
                    </article>
                    @empty
                    <div class="py-5 text-center text-sm text-gray-500">No email activity recorded yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
        <section>
            <div class="overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
                <div class="border-b border-[#E5E7EB] px-5 py-4">
                    <h3 class="font-bold text-[#111827]">Payment Shares</h3>
                </div>
                <div class="grid grid-cols-[1fr_auto_auto] gap-3 bg-[#F7F8FC] px-5 py-3 text-xs font-bold text-gray-500">
                    <div>Participant</div>
                    <div>Amount</div>
                    <div>Status</div>
                </div>
                <div class="divide-y divide-[#E5E7EB]">
                    @forelse($consultation->paymentRequests as $payment)
                    @php($status = $statusTheme($payment->status))
                    @php($payer = trim(($payment->participant?->first_name ?? 'Client').' '.($payment->participant?->last_name ?? '')))
                    <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 px-5 py-4 text-sm">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[#ECEDF9] text-xs font-bold text-[#082BC3]">{{ Str::upper(Str::substr($payer, 0, 2)) }}</div>
                            <div class="truncate font-semibold text-[#111827]">{{ $payer }}</div>
                        </div>
                        <div class="font-bold">${{ number_format($payment->amount_cents / 100, 2) }}</div>
                        <div class="flex items-center gap-2">
                            <span class="status-badge {{ $status['badge'] }}">{{ $status['label'] }}</span>
                            @if(filled($payment->payment_url) && $payment->status !== 'paid')
                            <a href="{{ $payment->payment_url }}" target="_blank" rel="noopener" aria-label="View Payment Link"><i data-lucide="link" class="h-4 w-4 text-[#082BC3]"></i><span class="sr-only">View Payment Link</span></a>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-500">No payment shares created yet.</div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-admin.layout>
