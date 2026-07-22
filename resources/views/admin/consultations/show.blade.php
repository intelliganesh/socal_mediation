<x-admin.layout heading="Consultation {{ $consultation->booking_number }}" subheading="{{ $consultation->type->name }}">
    @php
        $app = $consultation->application === 'legal'
            ? ['label' => 'Legal Consultation', 'bg' => '#E8DDE1', 'text' => '#75172E', 'bar' => '#75172E']
            : ['label' => 'SoCal Mediation Center', 'bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3'];

        $statusTheme = function (?string $status) {
            return match ($status) {
                'paid', 'not_required', 'scheduled' => ['bg' => '#BBF7D0', 'text' => '#166534', 'bar' => '#22C55E', 'label' => str_replace('_', ' ', ucfirst((string) $status))],
                'cancelled', 'failed' => ['bg' => '#F8EEF1', 'text' => '#B91C1C', 'bar' => '#B91C1C', 'label' => 'Cancelled'],
                'error' => ['bg' => '#FEE2E2', 'text' => '#EF4444', 'bar' => '#EF4444', 'label' => 'Error'],
                'partially_paid' => ['bg' => '#FEF3C7', 'text' => '#92400E', 'bar' => '#F59E0B', 'label' => 'Partially Paid'],
                'pending' => ['bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3', 'label' => 'Pending'],
                'pending_payment' => ['bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3', 'label' => 'Payment Pending'],
                default => ['bg' => '#F3F4F6', 'text' => '#4B5563', 'bar' => '#9CA3AF', 'label' => str_replace('_', ' ', ucfirst((string) $status))],
            };
        };

        $paymentStatus = $statusTheme($consultation->payment_status);
        $paidPaymentCount = $consultation->paymentRequests->where('status', 'paid')->count();
        $totalPaymentCount = $consultation->paymentRequests->count();
        $pendingPaymentCount = max(0, $totalPaymentCount - $paidPaymentCount);
        $collectedAmountCents = $consultation->paymentRequests->where('status', 'paid')->sum('amount_cents');
        $progressPercent = $totalPaymentCount ? ($paidPaymentCount / $totalPaymentCount) * 100 : 0;
        $unpaidPaymentCount = $consultation->paymentRequests
            ->filter(fn ($payment) => $payment->status !== 'paid' && filled($payment->payment_url))
            ->count();
        $emailActivities = $consultation->integrationLogs
            ->where('provider', 'mail')
            ->sortByDesc('created_at')
            ->values();
        $zoomStatus = filled($consultation->zoom_join_url) ? 'Generated' : ($consultation->consultation_mode === 'online' ? 'Pending' : 'Not Required');
        $outlookSync = $consultation->integrationLogs
            ->where('provider', 'outlook')
            ->sortByDesc('created_at')
            ->first();
        $outlookStatus = $outlookSync
            ? str_replace('_', ' ', ucfirst($outlookSync->status))
            : ($consultation->starts_at ? 'Sync Pending' : 'Not Scheduled');
    @endphp

    <section class="mb-5 overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-[#E5E7EB] px-6 py-5">
            <div class="flex items-center gap-4">
                {{-- <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-[#F1F6FE] text-lg font-bold text-[#082BC3]">A</div> --}}
                <div>
                    <h2 class="text-xl font-bold">Admin Actions</h2>
                    <p class="mt-1 text-sm font-semibold text-gray-500">Quick actions to manage this consultation.</p>
                </div>
            </div>
            <a class="inline-flex items-center rounded-lg border border-[#E5E7EB] px-4 py-2.5 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index') }}">Back to list</a>
        </div>
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-5">
            @if($unpaidPaymentCount > 0)
                <article class="flex min-h-72 min-w-0 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#F8EEF1]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/link-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#75172E]">Send Payment Links</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send secure payment links to the main client and unpaid participants.</p>
                    <form class="mt-auto pt-6" method="post" action="{{ route('admin.consultations.payment-links', $consultation) }}">
                        @csrf
                        <button class="grid h-11 w-11 place-items-center rounded-lg bg-[#F8EEF1] hover:bg-[#E8DDE1]" aria-label="Send Payment Links">
                            <img class="h-5 w-5" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                        </button>
                    </form>
                </article>

                <article class="flex min-h-72 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#F1F6FE]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/reminder-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#082BC3]">Send Reminder</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send a payment reminder to clients who have not paid yet.</p>
                    <form class="mt-auto pt-6" method="post" action="{{ route('admin.consultations.reminder', $consultation) }}">
                        @csrf
                        <button class="grid h-11 w-11 place-items-center rounded-lg bg-[#F1F6FE] hover:bg-[#ECEDF9]" aria-label="Send Reminder">
                            <img class="h-5 w-5" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                        </button>
                    </form>
                </article>
            @endif

            @if(filled($consultation->zoom_join_url))
                <article class="flex min-h-72 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#F1F6FE]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/link-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#082BC3]">Resend Zoom Link</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Send the existing Zoom meeting link to consultation participants.</p>
                    <form class="mt-auto pt-6" method="post" action="{{ route('admin.consultations.zoom-link', $consultation) }}">
                        @csrf
                        <button class="grid h-11 w-11 place-items-center rounded-lg bg-[#F1F6FE] hover:bg-[#ECEDF9]" aria-label="Resend Zoom Link">
                            <img class="h-5 w-5" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                        </button>
                    </form>
                </article>
            @endif

            @if($consultation->consultation_mode === 'online')
                <article class="flex min-h-72 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#ECFDF5]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/redo-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#059669]">Regenerate Meeting Link</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Create a new Zoom meeting link and update this booking.</p>
                    <form class="mt-auto pt-6" method="post" action="{{ route('admin.consultations.regenerate-zoom', $consultation) }}">
                        @csrf
                        <button class="grid h-11 w-11 place-items-center rounded-lg bg-[#ECFDF5] hover:bg-[#D1FAE5]" aria-label="Regenerate Meeting Link">
                            <img class="h-5 w-5" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                        </button>
                    </form>
                </article>
            @endif

            <article class="rounded-lg border border-[#E5E7EB] bg-white shadow-sm">
                <form class="flex min-h-72 flex-col items-center p-5 text-center" method="post" action="{{ route('admin.consultations.sync-outlook', $consultation) }}">
                    @csrf
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#ECFDF5]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/microsoftoutlook-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#10B981]">Sync This Booking To Outlook</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Add or update this booking on the Outlook calendar.</p>
                    <button class="mt-auto inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[#ECFDF5] px-4 py-2.5 text-sm font-bold text-[#10B981] hover:bg-[#D1FAE5]" aria-label="Sync This Booking To Outlook">
                        <span>Sync to Outlook</span>
                        <img class="h-4 w-4" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </button>
                </form>
            </article>

            @if($consultation->starts_at)
                <article class="flex min-h-72 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#FFF7ED]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/redo-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#D97706]">Reschedule Consultation</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Choose a new date and time for this consultation.</p>
                    <form class="mt-auto grid w-full min-w-0 gap-3 pt-5" method="post" action="{{ route('admin.consultations.reschedule', $consultation) }}">
                        @csrf
                        <input class="w-full min-w-0 rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-semibold text-[#111827]" type="datetime-local" name="starts_at" value="{{ old('starts_at', $consultation->starts_at->format('Y-m-d\\TH:i')) }}" required>
                        @error('starts_at')
                            <p class="text-left text-xs font-bold text-[#B91C1C]">{{ $message }}</p>
                        @enderror
                        <button class="w-full rounded-lg bg-[#F59E0B] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#D97706]">Reschedule</button>
                    </form>
                </article>
            @endif

            @if(! in_array($consultation->status, ['cancelled', 'failed'], true))
                <article class="flex min-h-72 flex-col items-center rounded-lg border border-[#E5E7EB] bg-white p-5 text-center shadow-sm">
                    <div class="grid h-16 w-16 place-items-center rounded-full bg-[#FEE2E2]">
                        <img class="h-8 w-8" src="{{ asset('admin-icons/cancel-error-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                    </div>
                    <h3 class="mt-5 text-base font-bold text-[#B91C1C]">Cancel Consultation</h3>
                    <p class="mt-3 text-sm font-semibold leading-6 text-gray-500">Cancel this consultation and mark the booking as cancelled.</p>
                    <form class="mt-auto pt-6" method="post" action="{{ route('admin.consultations.cancel', $consultation) }}" onsubmit="return confirm('Cancel this consultation?');">
                        @csrf
                        <button class="grid h-11 w-11 place-items-center rounded-lg bg-[#FEE2E2] hover:bg-[#F8EEF1]" aria-label="Cancel Consultation">
                            <img class="h-5 w-5" src="{{ asset('admin-icons/arrow-right-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                        </button>
                    </form>
                </article>
            @endif
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="rounded-xl border border-[#E5E7EB] bg-white p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold">Client & Booking</h2>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $app['label'] }}</span>
                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $paymentStatus['bg'] }}; color: {{ $paymentStatus['text'] }}">{{ $paymentStatus['label'] }}</span>
                    </div>
                </div>
                <dl class="mt-5 grid gap-4 text-sm md:grid-cols-3">
                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Primary client</dt><dd class="mt-1 font-bold">{{ trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet' }}</dd><dd class="mt-1 text-sm">{{ $consultation->primary_email ?: 'No email yet' }}</dd></div>

                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Professional</dt><dd class="mt-1 font-bold">{{ $consultation->professional?->name ?: 'Not assigned' }}</dd><dd class="mt-1 text-gray-500">{{ $consultation->professional?->title ?: 'No title recorded' }}</dd><dd class="mt-1 text-sm">{{ $consultation->professional?->email ?: 'Not provided' }}</dd></div>
                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Schedule</dt><dd class="mt-1 font-bold">{{ $consultation->starts_at?->format('M d, Y g:i A') ?? 'Not selected' }}</dd></div>
                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Meeting Mode</dt><dd class="mt-1 font-bold">{{ ucfirst((string) $consultation->consultation_mode) ?: 'Not selected' }}</dd></div>
                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Zoom</dt><dd class="mt-3">
                            <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ filled($consultation->zoom_join_url) ? '#BBF7D0' : '#F8EEF1' }}; color: {{ filled($consultation->zoom_join_url) ? '#166534' : '#B91C1C' }}">{{ $zoomStatus }}</span>
                        </dd>
                        @if(filled($consultation->zoom_join_url))
                            <dd class="mt-3"><a class="inline-flex rounded-lg border border-[#E5E7EB] px-3 py-2 text-xs font-bold hover:bg-[#F7F8FC]" href="{{ $consultation->zoom_join_url }}" target="_blank" rel="noopener">Open Zoom Link</a></dd>
                        @endif</div>
                    <div class="rounded-lg bg-[#F7F8FC] p-4"><dt class="font-bold text-gray-500">Outlook Calendar</dt><dd class="mt-3">
                            <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $outlookSync ? '#BBF7D0' : '#F8EEF1' }}; color: {{ $outlookSync ? '#166534' : '#B91C1C' }}">{{ $outlookStatus }}</span>
                        </dd>
                        @if($outlookSync?->created_at)
                            <dd class="mt-3 text-xs text-gray-500">Last sync {{ $outlookSync->created_at->format('M d, h:i A') }}</dd>
                        @endif</div>
                </dl>

                @if($consultation->description)
                    <p class="mt-4 rounded-lg p-4 text-sm font-semibold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $consultation->description }}</p>
                @endif
            </section>

            <section class="overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
                <div class="border-b border-[#E5E7EB] px-6 py-5">
                    <h2 class="text-lg font-bold">Participants</h2>
                </div>
                <div class="hidden md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#F7F8FC] text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">Phone</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB]">
                            @foreach($consultation->participants as $participant)
                                <tr>
                                    <td class="px-4 py-4 font-bold">{{ $participant->first_name }} {{ $participant->last_name }}</td>
                                    <td class="px-4 py-4">
                                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $participant->is_primary ? 'Main Client' : 'Participant' }}</span>
                                    </td>
                                    <td class="break-all px-4 py-4">{{ $participant->email ?: 'Not provided' }}</td>
                                    <td class="px-4 py-4">{{ trim(($participant->phone_country ?? '').' '.($participant->phone ?? '')) ?: 'Not provided' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-[#E5E7EB] md:hidden">
                    @foreach($consultation->participants as $participant)
                        <article class="p-4 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div class="font-bold">{{ $participant->first_name }} {{ $participant->last_name }}</div>
                                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $participant->is_primary ? 'Main Client' : 'Participant' }}</span>
                            </div>
                            <div class="mt-2 break-all text-gray-500">{{ $participant->email ?: 'Not provided' }}</div>
                            <div class="mt-1 text-gray-500">{{ trim(($participant->phone_country ?? '').' '.($participant->phone ?? '')) ?: 'Not provided' }}</div>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
                <div class="border-b border-[#E5E7EB] px-6 py-5">
                    <h2 class="text-lg font-bold">Email Activity</h2>
                </div>
                <div class="hidden md:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#F7F8FC] text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Template</th>
                                <th class="px-4 py-3">Recipient</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Queued</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB]">
                            @forelse($emailActivities as $activity)
                                @php($activityStatus = $statusTheme($activity->status))
                                <tr>
                                    <td class="px-4 py-4">
                                        <div class="font-bold">{{ $activity->action === 'manual_payment_reminder' ? 'Manual Reminder' : 'Payment Link' }}</div>
                                        <div class="mt-1 text-gray-500">{{ $activity->message ?: 'Email activity recorded for '.$consultation->booking_number }}</div>
                                    </td>
                                    <td class="break-all px-4 py-4">{{ data_get($activity->request_payload, 'recipient', 'Not recorded') }}</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $activityStatus['bg'] }}; color: {{ $activityStatus['text'] }}">{{ ucfirst($activity->status) }}</span></td>
                                    <td class="px-4 py-4">{{ $activity->created_at?->format('M d, h:i A') }}</td>
                                </tr>
                            @empty
                                @foreach($consultation->paymentRequests->whereNotNull('sent_at') as $payment)
                                    @php($recipient = $payment->participant?->email ?: $consultation->primary_email)
                                    @php($activityStatus = $statusTheme($payment->status === 'paid' ? 'paid' : 'pending'))
                                    <tr>
                                        <td class="px-4 py-4">
                                            <div class="font-bold">Payment Link</div>
                                            <div class="mt-1 text-gray-500">Payment link prepared for {{ $consultation->booking_number }}</div>
                                        </td>
                                        <td class="break-all px-4 py-4">{{ $recipient ?: 'Not recorded' }}</td>
                                        <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $activityStatus['bg'] }}; color: {{ $activityStatus['text'] }}">{{ $payment->status === 'paid' ? 'Paid' : 'Queued' }}</span></td>
                                        <td class="px-4 py-4">{{ $payment->sent_at?->format('M d, h:i A') }}</td>
                                    </tr>
                                @endforeach
                                @if($consultation->paymentRequests->whereNotNull('sent_at')->isEmpty())
                                    <tr><td class="px-4 py-8 text-center text-gray-500" colspan="4">No email activity recorded yet.</td></tr>
                                @endif
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-[#E5E7EB] md:hidden">
                    @forelse($emailActivities as $activity)
                        @php($activityStatus = $statusTheme($activity->status))
                        <article class="p-4 text-sm">
                            <div class="font-bold">{{ $activity->action === 'manual_payment_reminder' ? 'Manual Reminder' : 'Payment Link' }}</div>
                            <div class="mt-1 text-gray-500">{{ $activity->message ?: 'Email activity recorded for '.$consultation->booking_number }}</div>
                            <div class="mt-3 break-all">{{ data_get($activity->request_payload, 'recipient', 'Not recorded') }}</div>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $activityStatus['bg'] }}; color: {{ $activityStatus['text'] }}">{{ ucfirst($activity->status) }}</span>
                                <span class="text-gray-500">{{ $activity->created_at?->format('M d, h:i A') }}</span>
                            </div>
                        </article>
                    @empty
                        @foreach($consultation->paymentRequests->whereNotNull('sent_at') as $payment)
                            @php($recipient = $payment->participant?->email ?: $consultation->primary_email)
                            @php($activityStatus = $statusTheme($payment->status === 'paid' ? 'paid' : 'pending'))
                            <article class="p-4 text-sm">
                                <div class="font-bold">Payment Link</div>
                                <div class="mt-1 text-gray-500">Payment link prepared for {{ $consultation->booking_number }}</div>
                                <div class="mt-3 break-all">{{ $recipient ?: 'Not recorded' }}</div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $activityStatus['bg'] }}; color: {{ $activityStatus['text'] }}">{{ $payment->status === 'paid' ? 'Paid' : 'Queued' }}</span>
                                    <span class="text-gray-500">{{ $payment->sent_at?->format('M d, h:i A') }}</span>
                                </div>
                            </article>
                        @endforeach
                        @if($consultation->paymentRequests->whereNotNull('sent_at')->isEmpty())
                            <div class="px-4 py-8 text-center text-sm text-gray-500">No email activity recorded yet.</div>
                        @endif
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
                <div class="border-b border-[#E5E7EB] px-6 py-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-bold">Payment Progress</h2>
                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $paymentStatus['bg'] }}; color: {{ $paymentStatus['text'] }}">{{ $paymentStatus['label'] }}</span>
                    </div>
                </div>
                <div class="px-6 py-5 text-sm text-gray-500">
                    <div class="flex items-center justify-between gap-4">
                        <span>{{ $totalPaymentCount }} {{ Str::plural('participant', $totalPaymentCount) }}</span>
                        <span class="font-bold">{{ $paidPaymentCount }} Paid · {{ $pendingPaymentCount }} Pending</span>
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-[#E5E7EB]">
                        <div class="h-2 rounded-full bg-[#082BC3]" style="width: {{ $progressPercent }}%"></div>
                    </div>
                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span>${{ number_format($collectedAmountCents / 100, 2) }} collected</span>
                        <span>${{ number_format($consultation->total_amount_cents / 100, 2) }} total</span>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
                <div class="border-b border-[#E5E7EB] px-6 py-5">
                    <h2 class="text-lg font-bold">Payment Shares</h2>
                </div>
                <div class="hidden grid-cols-3 bg-[#F7F8FC] px-4 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 md:grid">
                    <div>Payer</div>
                    <div>Amount</div>
                    <div>Status</div>
                </div>
                <div class="divide-y divide-[#E5E7EB]">
                    @foreach($consultation->paymentRequests as $payment)
                        @php($status = $statusTheme($payment->status))
                        <div class="grid gap-3 px-4 py-4 text-sm md:grid-cols-3 md:items-center">
                            <div class="min-w-0">
                                <div class="font-bold">{{ trim(($payment->participant?->first_name ?? 'Client').' '.($payment->participant?->last_name ?? '')) }}</div>

                            </div>
                            <div class="font-bold" style="color: {{ $app['bar'] }}">${{ number_format($payment->amount_cents / 100, 2) }}</div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $status['bg'] }}; color: {{ $status['text'] }}">{{ $status['label'] }}</span>
                                @if(filled($payment->payment_url) && $payment->status !== 'paid')
                                    <a class="grid h-4 w-4 place-items-center  hover:bg-[#F7F8FC]" href="{{ $payment->payment_url }}" target="_blank" rel="noopener" aria-label="View Payment Link" title="View Payment Link">
                                        <img class="h-4 w-4" src="{{ asset('admin-icons/link-svgrepo-com.svg') }}" alt="" aria-hidden="true">
                                        <span class="sr-only">View Payment Link</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if($consultation->paymentRequests->isEmpty())
                        <div class="px-4 py-8 text-center text-sm text-gray-500">No payment shares created yet.</div>
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-admin.layout>
