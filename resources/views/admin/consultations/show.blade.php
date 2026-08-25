<x-admin.layout heading="" subheading="" breadcrumb="Consultations" :application="$consultation->application">
    @php
    $app = $consultation->application === 'legal'
    ? ['label' => 'Law Office', 'icon' => 'scale', 'theme' => 'app-theme-legal', 'iconClass' => 'app-icon-legal', 'textClass' => 'app-text-legal', 'progress' => 'app-progress-legal', 'color' => '#75172E', 'soft' => '#E8DDE1']
    : ['label' => 'SoCal Mediation Center', 'icon' => 'landmark', 'theme' => 'app-theme-socal', 'iconClass' => 'app-icon-socal', 'textClass' => 'app-text-socal', 'progress' => 'app-progress-socal', 'color' => '#082BC3', 'soft' => '#F1F6FE'];
    $statusTheme = function (?string $status) {
    return match ($status) {
    'paid' => ['badge' => 'status-badge-paid', 'progress' => 'progress-fill-paid', 'label' => 'Paid'],
    'scheduled' => ['badge' => 'status-badge-scheduled', 'progress' => 'progress-fill-scheduled', 'label' => 'Scheduled'],
    'cancelled' => ['badge' => 'status-badge-cancelled', 'progress' => 'progress-fill-cancelled', 'label' => 'Cancelled'],
    'completed' => ['badge' => 'status-badge-completed', 'progress' => 'progress-fill-completed', 'label' => 'Completed'],
    'rescheduled' => ['badge' => 'status-badge-rescheduled', 'progress' => 'progress-fill-scheduled', 'label' => 'Rescheduled'],
    // 'in_progress' => ['badge' => 'status-badge-in-progress', 'progress' => 'progress-fill-scheduled', 'label' => 'In Progress'],
    'overdue' => ['badge' => 'status-badge-overdue', 'progress' => 'progress-fill-overdue', 'label' => 'Overdue'],
    'partially_paid' => ['badge' => 'status-badge-partially-paid', 'progress' => 'progress-fill-partially-paid', 'label' => 'Partially Paid'],
    'pending', 'payment_pending' => ['badge' => 'status-badge-pending', 'progress' => 'progress-fill-pending', 'label' => $status === 'payment_pending' ? 'Payment Pending' : 'Pending'],
    default => ['badge' => 'status-badge-draft', 'progress' => 'progress-fill-draft', 'label' => str_replace('_', ' ', ucfirst((string) $status ?: 'Not Required'))],
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
    'automatic_zoom_link' => ['label' => 'Zoom Link', 'icon' => 'video'],
    'automatic_confirmation' => ['label' => 'Confirmation', 'icon' => 'check-circle'],
    'manual_conclusion' => ['label' => 'Conclusion', 'icon' => 'check-check'],
    'questionnaire_link' => ['label' => 'Questionnaire Link', 'icon' => 'clipboard-list'],
    'free_intro_schedule_invite' => ['label' => 'Free Intro Slot Invite', 'icon' => 'calendar-plus'],
    'free_intro_confirmation' => ['label' => 'Free Intro Confirmation', 'icon' => 'calendar-check'],
    default => ['label' => Str::headline($action), 'icon' => 'mail'],
    };
    };
    $outlookSync = $consultation->integrationLogs->where('provider', 'outlook')->sortByDesc('created_at')->first();
    $outlookStatus = $outlookSync ? str_replace('_', ' ', ucfirst($outlookSync->status)) : ($consultation->starts_at ? 'Sync Pending' : 'Not Scheduled');
    $zoomStatus = filled($consultation->zoom_join_url) ? 'Generated' : ($consultation->consultation_mode === 'online' ? 'Pending' : 'Not Required');
    $primaryName = trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet';
    $consultationStatusOptions = ['draft', 'pending', 'payment_pending', 'paid', 'scheduled', 'rescheduled', 'in_progress', 'completed', 'cancelled', 'overdue'];
    $paymentStatusOptions = ['pending', 'partially_paid', 'paid', 'failed', 'refunded'];
    $questionnaireSubmissions = $consultation->questionnaireSubmissions;
    @endphp
<div style="--admin-brand: {{ $app['color'] }}; --admin-brand-soft: {{ $app['soft'] }};">
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
        <form class="action-card action-card-primary min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.reminder', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="bell" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Send Reminder</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send Payment Reminder for unpaid participants.</p>
            <button class="action-card-button mt-auto h-10 w-full rounded-lg text-sm font-bold">Send Reminder</button>
        </form>
        @endif

        @if($consultation->starts_at)
        <form class="action-card action-card-pending min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.reschedule', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="refresh-cw" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Reschedule Meeting</h3>
            <p class="mt-2 text-sm font-semibold text-gray-500">Reschedule meeting and send new meeting link to all participants.</p>
            <div class="mt-auto">
                <input class="mt-3 h-9 w-full min-w-0 rounded-lg border border-[#E5E7EB] px-3 text-xs font-bold text-[#111827]" type="datetime-local" name="starts_at" value="{{ old('starts_at', $consultation->starts_at->format('Y-m-d\\TH:i')) }}" required>
                <button class="action-card-button mt-2 h-10 w-full rounded-lg text-sm font-bold">Reschedule</button>
            </div>
        </form>
        @endif
        <form class="action-card action-card-zoom min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.zoom-link', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="video" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Send Zoom Links</h3>
            <span class="sr-only">Resend Zoom Link</span>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send Zoom meeting links to all the participants.</p>
            <div class="mt-auto flex gap-2">
                <button class="action-card-button h-10 w-full rounded-lg text-sm font-bold">Send Zoom Link</button>
                @if(filled($consultation->zoom_join_url))
                <a class="admin-brand-zoom p-4 flex h-10 items-center justify-center gap-2 rounded-lg border border-[#844fc1] bg-white text-sm font-bold" href="{{ $consultation->zoom_join_url }}" target="_blank" rel="noopener">

                    <i data-lucide="external-link" class="h-4 w-4"></i>
                </a>
                @endif
            </div>
        </form>

        <form class="action-card action-card-paid min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.sync-outlook', $consultation) }}">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="calendar-plus" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Sync This Booking To Outlook</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Sync Consultation to outlook.</p>
            <button class="action-card-button mt-auto h-10 w-full rounded-lg text-sm font-bold">Sync to Outlook</button>
        </form>
        @if(! in_array($consultation->status, ['cancelled', 'failed'], true))
        <form class="action-card action-card-cancelled min-h-48 rounded-xl border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.06)]" method="post" action="{{ route('admin.consultations.cancel', $consultation) }}" onsubmit="return confirm('Cancel this consultation?');">
            @csrf
            <div class="action-card-icon grid h-12 w-12 place-items-center rounded-lg"><i data-lucide="x" class="h-7 w-7"></i></div>
            <h3 class="mt-4 font-bold">Cancel Consultation</h3>
            <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Cancel Consultation.</p>
            <button class="action-card-button mt-auto h-10 w-full rounded-lg text-sm font-bold">Cancel Consultation</button>
        </form>
        @endif
    </section>
    <section class="mb-5 grid gap-5 xl:grid-cols-3">
        <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="flex items-center justify-between border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="circle-user-round" class="admin-brand-text h-5 w-5"></i>Primary Client Information</h3>
            </div>
            <div class="flex items-center gap-5 p-5">
                <div class="grid h-24 w-24 shrink-0 place-items-center rounded-full text-4xl font-semibold {{ $app['iconClass'] }}">{{ Str::upper(Str::substr($primaryName, 0, 2)) }}</div>
                <div class="min-w-0 text-sm">
                    <div class="text-xl font-bold text-[#111827]">{{ $primaryName }}</div>
                    <div class="admin-brand-text mt-2 truncate font-semibold">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                    <div class="mt-3 text-[#111827]">{{ trim(($consultation->primary_phone_country ?? '').' '.($consultation->primary_phone ?? '')) ?: 'No phone yet' }}</div>
                    {{-- <span class="mt-4 inline-flex rounded-lg bg-[#F3F4F6] px-3 py-2 text-sm font-semibold text-gray-500">Client</span> --}}
                </div>
            </div>
        </article>
        <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="flex items-center justify-between border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="info" class="admin-brand-text h-5 w-5"></i>Consultation Information</h3>
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
                        <dd class="mt-1 flex font-semibold text-[#111827]">{{ $zoomStatus }} @if(filled($consultation->zoom_join_url)) <a class="admin-brand-link ml-2 font-bold" href="{{ $consultation->zoom_join_url }}" target="_blank" rel="noopener"><i data-lucide="external-link" class="h-4 w-4"></i></a> @endif</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="clock" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Consultation Type</dt>
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->type->name }}</dd>
                    </div>
                </div>
                <div class="flex gap-3"><i data-lucide="calendar-check" class="h-5 w-5 text-gray-500"></i>
                    <div><dt class="text-gray-500">Outlook Event</dt>
                        <dd class="admin-brand-text mt-1 font-semibold">{{ $outlookStatus }}</dd>
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
                        <dd class="mt-1 font-semibold text-[#111827]">{{ $consultation->referral_source_display ?: 'Not provided' }}</dd>
                        @if(strcasecmp((string) $consultation->referral_source, 'Other Referral') === 0 && filled($consultation->referral_source_others))
                        <dd class="mt-1 text-xs font-semibold text-gray-500">Other Referral</dd>
                        @endif
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
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="sliders-horizontal" class="admin-brand-text h-5 w-5"></i>Status Controls</h3>
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
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-bold text-[#111827]">Participants</h3>
                    <span class="text-sm font-bold text-gray-500">Payment Shares</span>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach($consultation->participants as $participant)
                    @php($participantName = trim($participant->first_name.' '.$participant->last_name))
                    @php($participantPayment = $consultation->paymentRequests->firstWhere('participant_id', $participant->id))
                    @php($participantStatus = $statusTheme($participantPayment?->status))
                    @php($repeatFreeIntroParticipant = $repeatFreeIntroParticipants->get($participant->id))
                    <article class="rounded-lg border p-3 text-sm {{ $repeatFreeIntroParticipant ? 'border-amber-300 bg-amber-50/70 ring-1 ring-amber-200' : 'border-[#E5E7EB]' }}">
                        <div class="flex items-start gap-3">
                            <div class="admin-brand-soft grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-bold">{{ Str::upper(Str::substr($participantName, 0, 2)) }}</div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="truncate font-bold text-[#111827]">{{ $participantName }}</div>
                                    @if($repeatFreeIntroParticipant)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-white px-2 py-0.5 text-[11px] font-bold text-amber-800">
                                        <i data-lucide="repeat-2" class="h-3 w-3"></i>
                                        Repeat Intro
                                    </span>
                                    @endif
                                </div>
                                <div class="truncate text-xs text-gray-500">{{ $participant->email ?: 'Not provided' }}</div>
                                @if($consultation->type->slug === 'socal-free-intro-call')
                                <div class="mt-1 text-xs font-semibold text-gray-500">
                                    {{ Str::headline($participant->scheduling_status ?? 'pending') }}
                                    @if($participant->scheduled_starts_at)
                                    - {{ $participant->scheduled_starts_at->format('M d, Y g:i A') }}
                                    @endif
                                </div>
                                @endif
                                @if($repeatFreeIntroParticipant)
                                <div class="mt-2 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-900">
                                    Previously completed Free 15-Min Intro Call
                                    <span class="block text-amber-800">
                                        {{ $repeatFreeIntroParticipant->consultation?->booking_number }}
                                        @if($repeatFreeIntroParticipant->consultation?->starts_at)
                                        - {{ $repeatFreeIntroParticipant->consultation->starts_at->format('M d, Y g:i A') }}
                                        @endif
                                    </span>
                                </div>
                                @endif
                                <div class="font-bold mt-2">${{ number_format($participantPayment?->amount_cents / 100, 2) }}</div>

                                <div class="flex items-center gap-2 mt-2">
                                <div class="">
                                    <span class="status-badge {{ $participantStatus['badge'] }}">{{ $participantStatus['label'] }}</span>
                                </div>
                                    @if(filled($participantPayment?->payment_url) && $participantPayment?->status !== 'paid')
                                    <button class="copy-payment-link inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white hover:bg-[#F7F8FC]" type="button" data-payment-url="{{ $participantPayment->payment_url }}" aria-label="Copy Payment Link" title="Copy Payment Link"><i data-lucide="link" class="admin-brand-text h-4 w-4"></i><span class="sr-only">Copy Payment Link</span></button>
                                    @endif
                                </div>
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
    <section class="mb-5 overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E7EB] px-5 py-4">
            <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="clipboard-list" class="admin-brand-text h-5 w-5"></i>Questionnaires</h3>
            @if($questionnaireSubmissions->isNotEmpty())
            <span class="text-sm font-bold text-gray-500">{{ $questionnaireSubmissions->where('status', 'submitted')->count() }} of {{ $questionnaireSubmissions->count() }} submitted</span>
            @endif
        </div>
        <div class="divide-y divide-[#E5E7EB]">
            @forelse($questionnaireSubmissions as $submission)
            @php($submissionTemplate = app(\App\Services\QuestionnaireTemplateService::class)->template($submission->template_key))
            @php($submissionStatus = $statusTheme($submission->status))
            @php($submissionParticipant = $submission->participant)
            @php($submissionName = trim(($submissionParticipant?->first_name ?? '').' '.($submissionParticipant?->last_name ?? '')) ?: 'Participant')
            <article class="grid gap-5 px-5 py-5 lg:grid-cols-[280px_minmax(0,1fr)_auto]">
                <div class="min-w-0 text-sm">
                    <div class="font-bold text-[#111827]">{{ $submissionName }}</div>
                    <div class="mt-1 truncate text-gray-500">{{ $submissionParticipant?->email ?: 'No email recorded' }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="status-badge {{ $submissionStatus['badge'] }}">{{ $submissionStatus['label'] }}</span>
                        @if($submissionTemplate['requires_agreement'] ?? false)
                        <span class="status-badge {{ $submission->agreement_accepted ? 'status-badge-paid' : 'status-badge-pending' }}">
                            {{ $submission->agreement_accepted ? 'Agreement Accepted' : 'Agreement Pending' }}
                        </span>
                        @endif
                    </div>
                    <div class="mt-3 text-xs font-semibold text-gray-500">
                        {{ $submissionTemplate['label'] ?? Str::headline($submission->template_key) }}
                        <br>
                        Submitted: {{ $submission->submitted_at?->format('M d, Y g:i A') ?? 'Pending' }}
                    </div>
                </div>
                <div class="min-w-0">
                    @if($submission->status === 'submitted')
                    <dl class="grid gap-3 text-sm md:grid-cols-2">
                        @forelse(($submission->answers ?? []) as $answerKey => $answer)
                        <div class="rounded-lg bg-[#F7F8FC] p-3">
                            <dt class="text-xs font-bold uppercase text-gray-500">{{ Str::headline((string) $answerKey) }}</dt>
                            <dd class="mt-1 break-words font-semibold text-[#111827]">
                                @if(is_array($answer))
                                {{ implode(', ', $answer) ?: 'Not answered' }}
                                @elseif(is_bool($answer))
                                {{ $answer ? 'Yes' : 'No' }}
                                @else
                                {{ filled($answer) ? $answer : 'Not answered' }}
                                @endif
                            </dd>
                        </div>
                        @empty
                        <div class="rounded-lg bg-[#F7F8FC] p-3 text-sm font-semibold text-gray-500">No answers submitted.</div>
                        @endforelse
                    </dl>
                    @else
                    <div class="rounded-lg bg-[#F7F8FC] p-4 text-sm font-semibold text-gray-500">
                        The questionnaire has not been submitted yet.
                    </div>
                    @endif
                </div>
                <div class="flex items-start justify-end">
                    @if($submission->status === 'submitted')
                    <a class="inline-flex h-10 items-center gap-2 rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.questionnaires.pdf', [$consultation, $submission]) }}">
                        <i data-lucide="download" class="h-4 w-4"></i>
                        PDF
                    </a>
                    @endif
                </div>
            </article>
            @empty
            <div class="px-5 py-8 text-center text-sm font-semibold text-gray-500">No questionnaire is required yet. Questionnaires are created after payment is received.</div>
            @endforelse
        </div>
    </section>
    <div class="grid gap-5 xl:grid-cols-3 ">
        <div class="space-y-5 ">
            <section class="rounded-lg border border-[#E5E7EB] bg-white  shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
                <div class="border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="mail-check" class="admin-brand-text h-5 w-5"></i>Email Activity</h3>
            </div>
                <div class="space-y-3 p-5">
                    @forelse($emailActivities as $activity)
                    @php($activityStatus = $statusTheme($activity->status))
                    @php($activityMeta = $emailActivityMeta($activity->action))
                    <article class="flex items-center gap-3 text-sm ">
                        <div class="admin-brand-button grid h-9 w-9 shrink-0 place-items-center rounded-full"><i data-lucide="{{ $activityMeta['icon'] }}" class="h-5 w-5"></i></div>
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
        {{-- <section>
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
                            <div class="admin-brand-soft grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold">{{ Str::upper(Str::substr($payer, 0, 2)) }}</div>
                            <div class="truncate font-semibold text-[#111827]">{{ $payer }}</div>
                        </div>
                        <div class="font-bold">${{ number_format($payment->amount_cents / 100, 2) }}</div>
                        <div class="flex items-center gap-2">
                            <span class="status-badge {{ $status['badge'] }}">{{ $status['label'] }}</span>
                            @if(filled($payment->payment_url) && $payment->status !== 'paid')
                            <button class="copy-payment-link inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white hover:bg-[#F7F8FC]" type="button" data-payment-url="{{ $payment->payment_url }}" aria-label="Copy Payment Link" title="Copy Payment Link"><i data-lucide="link" class="admin-brand-text h-4 w-4"></i><span class="sr-only">Copy Payment Link</span></button>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-500">No payment shares created yet.</div>
                    @endforelse
                </div>
            </div>
        </section> --}}
        <section class="xl:col-span-2 overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="border-b border-[#E5E7EB] px-5 py-4">
                <h3 class="flex items-center gap-3 font-bold text-[#111827]"><i data-lucide="activity" class="admin-brand-text h-5 w-5"></i>Payment Gateway Activity</h3>
            </div>
            <div class="divide-y divide-[#E5E7EB]">
                @forelse($paymentGatewayActivities as $activity)
                @php($log = $activity['log'])
                @php($payment = $activity['payment'])
                @php($gatewayStatus = $statusTheme($log->status))
                @php($payerName = trim(($payment->participant?->first_name ?? $consultation->primary_first_name).' '.($payment->participant?->last_name ?? $consultation->primary_last_name)))
                <article class="grid gap-3 px-5 py-4 text-sm lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-[#111827]">{{ Str::headline($log->action) }}</span>
                            <span class="status-badge {{ $gatewayStatus['badge'] }}">{{ Str::headline($log->status) }}</span>
                            <span class="font-bold text-gray-500">PAY-{{ $log->id }}</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">{{ $payerName ?: 'Unknown payer' }} · ${{ number_format($payment->amount_cents / 100, 2) }} {{ $payment->currency }}</div>
                        @if(filled($log->message))
                        <div class="mt-3 break-words rounded-lg bg-[#F7F8FC] p-3 font-semibold text-[#374151]">{{ $log->message }}</div>
                        @endif
                    </div>
                    <time class="text-xs font-semibold text-gray-500" datetime="{{ $log->created_at?->toIso8601String() }}">{{ $log->created_at?->format('M d, Y g:i A') }}</time>
                </article>
                @empty
                <div class="px-5 py-8 text-center text-sm text-gray-500">No payment gateway activity recorded yet.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
<script>
    document.querySelectorAll('.copy-payment-link').forEach((button) => {
        button.addEventListener('click', async () => {
            const paymentUrl = button.dataset.paymentUrl || '';
            if (! paymentUrl) {
                return;
            }

            try {
                await navigator.clipboard.writeText(paymentUrl);
                button.setAttribute('title', 'Copied');
                button.setAttribute('aria-label', 'Payment Link Copied');
            } catch (error) {
                const fallback = document.createElement('textarea');
                fallback.value = paymentUrl;
                fallback.setAttribute('readonly', '');
                fallback.style.position = 'absolute';
                fallback.style.left = '-9999px';
                document.body.appendChild(fallback);
                fallback.select();
                document.execCommand('copy');
                fallback.remove();
                button.setAttribute('title', 'Copied');
                button.setAttribute('aria-label', 'Payment Link Copied');
            }
        });
    });
</script>
</x-admin.layout>
