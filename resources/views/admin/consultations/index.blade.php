<x-admin.layout heading="Consultations" subheading="Filter and review bookings from both application flows." :application="$selectedApplication">
    @php
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['label' => 'Legal Consultation', 'icon' => 'scale', 'theme' => 'app-theme-legal', 'iconClass' => 'app-icon-legal', 'textClass' => 'app-text-legal', 'progress' => 'app-progress-legal']
            : ['label' => 'SoCal Mediation Center', 'icon' => 'landmark', 'theme' => 'app-theme-socal', 'iconClass' => 'app-icon-socal', 'textClass' => 'app-text-socal', 'progress' => 'app-progress-socal'];
        $currentUser = auth()->user();

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
    @endphp

    <form class="mb-5 rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]" method="get">
        <div class="grid gap-3 xl:grid-cols-[minmax(220px,1fr)_150px_140px_170px_170px_auto_auto_auto] xl:items-center">
            <label class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500"></i>
                <input class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white pl-10 pr-3 text-sm font-semibold text-[#111827] placeholder:text-gray-500" type="search" name="q" value="{{ request('q') }}" placeholder="Search consultation...">
            </label>
            @if($currentUser?->isGlobalAdmin())
                <select class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" name="application">
                    <option value="">All Applications</option>
                    <option value="socal" @selected($selectedApplication === 'socal')>SoCal Mediation Center</option>
                    <option value="legal" @selected($selectedApplication === 'legal')>Legal Consultation</option>
                </select>
            @else
                @php($assignedTheme = $applicationTheme($selectedApplication))
                <div class="flex h-11 items-center rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-bold {{ $assignedTheme['textClass'] }}">{{ $assignedTheme['label'] }}</div>
            @endif
            <select class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" name="status">
                <option value="">All Statuses</option>
                @foreach(['draft', 'payment_pending', 'paid', 'scheduled', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                @endforeach
            </select>
            <label class="relative">
                <span class="sr-only">Date from</span>
                <input class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" type="date" name="date_from" value="{{ request('date_from') }}">
            </label>
            <label class="relative">
                <span class="sr-only">Date to</span>
                <input class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" type="date" name="date_to" value="{{ request('date_to') }}">
            </label>
            <a class="flex h-11 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-semibold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index') }}">Reset</a>
            <button class="admin-brand-button h-11 rounded-lg px-5 text-sm font-bold" type="submit">Apply</button>
            <a class="flex h-11 items-center justify-center gap-2 rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index', array_merge(request()->query(), ['export' => 1])) }}">
                <i data-lucide="download" class="h-4 w-4"></i>
                Export
            </a>
        </div>
    </form>

    <section class="overflow-hidden rounded-2xl border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="grid divide-y divide-[#E5E7EB] md:hidden">
            @forelse($consultations as $consultation)
                @php($app = $applicationTheme($consultation->application))
                @php($status = $statusTheme($consultation->status))
                @php($paymentStatus = $statusTheme($consultation->payment_status))
                @php($payerCount = max(1, $consultation->paymentRequests->count()))
                @php($paidCount = $consultation->paymentRequests->where('status', 'paid')->count())
                @php($progressPercent = round($payerCount ? ($paidCount / $payerCount) * 100 : 0))
                @php($name = trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet')
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold {{ $app['iconClass'] }}">{{ Str::upper(Str::substr($name, 0, 2)) }}</div>
                            <div class="min-w-0">
                                <div class="truncate font-bold text-[#111827]">{{ $name }}</div>
                                <div class="truncate text-xs font-semibold text-gray-500">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                            </div>
                        </div>
                        <span class="status-badge {{ $status['badge'] }} shrink-0">{{ $status['label'] }}</span>
                    </div>
                    <div class="mt-4 grid gap-3 text-sm">
                        <div>
                            <div class="font-bold text-[#111827]">{{ $consultation->booking_number }}</div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $app['theme'] }}">{{ $app['label'] }}</span>
                                <span class="font-semibold text-[#111827]">{{ $consultation->type->name }}</span>
                            </div>
                        </div>
                        <div class="text-gray-500">{{ $consultation->starts_at?->format('M d, Y g:i A') ?? 'Not scheduled' }}</div>
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-4">
                                <span class="text-gray-500">{{ $payerCount }} {{ Str::plural('payer', $payerCount) }}</span>
                                <span class="font-bold text-[#111827]">{{ $paidCount }} Paid</span>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill {{ $paymentStatus['progress'] }}" style="width: {{ $progressPercent }}%"></div>
                            </div>
                        </div>
                        <div class="font-bold text-[#111827]">Total: ${{ number_format($consultation->total_amount_cents / 100, 2) }}</div>
                    </div>
                    <a class="mt-4 flex h-10 items-center justify-center rounded-lg border border-[#E5E7EB] font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.show', $consultation) }}">Review</a>
                </article>
            @empty
                <div class="px-4 py-10 text-center text-gray-500">No consultations found.</div>
            @endforelse
        </div>

        <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[1120px] text-left text-sm">
                <thead class="text-xs font-bold text-gray-500">
                    <tr class="border-b border-[#E5E7EB]">
                        <th class="px-5 py-4">Consultation #</th>
                        <th class="px-5 py-4">Client</th>
                        <th class="px-5 py-4">Type</th>
                        <th class="px-5 py-4">Date & Time</th>
                        <th class="px-5 py-4">Total Amount</th>
                        <th class="px-5 py-4">Payment Progress</th>
                        <th class="px-5 py-4">Status</th>
                        <th class="px-5 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB]">
                    @forelse($consultations as $consultation)
                        @php($app = $applicationTheme($consultation->application))
                        @php($status = $statusTheme($consultation->status))
                        @php($paymentStatus = $statusTheme($consultation->payment_status))
                        @php($payerCount = max(1, $consultation->paymentRequests->count()))
                        @php($paidCount = $consultation->paymentRequests->where('status', 'paid')->count())
                        @php($progressPercent = round($payerCount ? ($paidCount / $payerCount) * 100 : 0))
                        @php($name = trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet')
                        <tr class="align-middle hover:bg-[#FAFAFB]">
                            <td class="px-5 py-4">
                                <div class="font-bold text-[#111827]">{{ $consultation->booking_number }}</div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-full text-base font-bold {{ $app['theme'] }}">{{ Str::upper(Str::substr($name, 0, 2)) }}</div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-[#111827]">{{ $name }}</div>
                                        <div class="truncate text-xs font-semibold text-gray-500">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                {{-- <div class="font-bold text-[#111827]">{{ $consultation->type->name }}</div> --}}
                                <div class="">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $app['theme'] }}" style="background: none;">{{ $consultation->type->name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-4 font-semibold text-[#111827]">{{ $consultation->starts_at?->format('M d, Y') ?? 'Not scheduled' }}<br>{{ $consultation->starts_at?->format('g:i A') ?? '' }}</td>

                            <td class="px-5 py-4 font-bold text-[#111827]">${{ number_format($consultation->total_amount_cents / 100, 2) }}</td>
                            <td class="px-5 py-4">
                                {{-- <div class="mb-3 flex w-40 items-center justify-between gap-4 text-sm">
                                    <span class="text-gray-500">{{ $payerCount }} {{ Str::plural('payer', $payerCount) }}</span>
                                    <span class="font-bold text-[#111827]">{{ $paidCount }} Paid</span>
                                </div>
                                <div class="progress-track w-40">
                                    <div class="progress-fill {{ $paymentStatus['progress'] }}" style="width: {{ $progressPercent }}%"></div>
                                </div> --}}
                                <div class="w-40 items-center text-sm">
                                    <span class="text-gray-500">{{ $payerCount }} {{ Str::plural('payer', $payerCount) }} </span> /
                                    <span class="font-bold text-[#111827]"> {{ $paidCount }} Paid</span>
                                </div>
                            </td>
                            <td class="px-5 py-4"><span class="status-badge {{ $status['badge'] }}">{{ $status['label'] }}</span></td>
                            <td class="px-5 py-4">
                                <a class="ml-auto grid h-11 w-11 place-items-center rounded-lg border border-[#E5E7EB] text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.show', $consultation) }}" aria-label="Open consultation">
                                    <i data-lucide="eye" class="h-5 w-5">View</i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-gray-500" colspan="8">No consultations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-5 rounded-2xl border border-[#E5E7EB] bg-white px-5 py-4 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm font-semibold text-gray-500">Showing {{ $consultations->firstItem() ?? 0 }} to {{ $consultations->lastItem() ?? 0 }} of {{ $consultations->total() }} results</div>
            <div>{{ $consultations->links() }}</div>
        </div>
    </div>
</x-admin.layout>
