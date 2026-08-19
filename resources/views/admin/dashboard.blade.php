<x-admin.layout heading="Dashboard" subheading="Overview of your mediation center operations and performance." :application="auth()->user()?->application">
    @php
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['label' => 'Law Office', 'icon' => 'scale', 'theme' => 'app-theme-legal', 'iconClass' => 'app-icon-legal', 'textClass' => 'app-text-legal', 'progress' => 'app-progress-legal']
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
                'partially_paid' => ['badge' => 'status-badge-partially-paid', 'progress' => 'progress-fill-partially-paid', 'label' => 'Partial'],
                'pending', 'payment_pending' => ['badge' => 'status-badge-pending', 'progress' => 'progress-fill-pending', 'label' => $status === 'payment_pending' ? 'Payment Pending' : 'Pending'],
                default => ['badge' => 'status-badge-draft', 'progress' => 'progress-fill-draft', 'label' => str_replace('_', ' ', ucfirst((string) $status ?: 'Draft'))],
            };
        };

        $statCards = [
            ['label' => 'Total Consultations', 'value' => $totals['consultations'], 'icon' => 'users', 'class' => 'app-theme-socal', 'trend' => '8%'],
            ['label' => 'Draft Consultations', 'value' => $totals['drafts'], 'icon' => 'file-text', 'class' => 'status-badge-pending', 'trend' => '0%'],
            ['label' => 'Scheduled Consultations', 'value' => $totals['scheduled'], 'icon' => 'calendar-days', 'class' => 'status-badge-paid', 'trend' => '19%'],
            ['label' => 'Revenue', 'value' => '$'.number_format($totals['revenue_cents'] / 100, 2), 'icon' => 'circle-dollar-sign', 'class' => 'app-theme-legal', 'trend' => '11%'],
        ];
        $paymentRows = [
            'paid' => ['label' => 'Paid', ...($paymentMix['paid'] ?? ['count' => 0, 'percent' => 0])],
            'pending' => ['label' => 'Pending', ...($paymentMix['pending'] ?? ['count' => 0, 'percent' => 0])],
            'partially_paid' => ['label' => 'Partial', ...($paymentMix['partially_paid'] ?? ['count' => 0, 'percent' => 0])],
        ];
        $paidEnd = $paymentRows['paid']['percent'];
        $pendingEnd = $paidEnd + $paymentRows['pending']['percent'];
        $partialEnd = min(100, $pendingEnd + $paymentRows['partially_paid']['percent']);
        $paymentMixStyle = $paymentMixTotal > 0
            ? "background: conic-gradient(var(--status-paid-text) 0 {$paidEnd}%, var(--status-pending-border) {$paidEnd}% {$pendingEnd}%, var(--status-partial-text) {$pendingEnd}% {$partialEnd}%, var(--admin-muted-bg) {$partialEnd}% 100%)"
            : 'background: var(--admin-muted-bg)';
    @endphp

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach($statCards as $card)
            <section class="rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
                <div class="flex items-start gap-4">
                    <div class="grid h-14 w-14 shrink-0 place-items-center rounded-xl {{ $card['class'] }}">
                        <i data-lucide="{{ $card['icon'] }}" class="h-7 w-7"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-bold text-[#111827]">{{ $card['label'] }}</div>
                        <div class="mt-2 flex items-end justify-between gap-3">
                            <div class="text-3xl font-bold tracking-tight text-[#111827]">{{ $card['value'] }}</div>
                        </div>
                        {{-- <div class="mt-2 text-xs font-semibold text-gray-500">vs last 30 days</div> --}}
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="mb-4 flex items-center gap-2">
                <i data-lucide="layout-grid" class="h-5 w-5 text-gray-500"></i>
                <h2 class="text-lg font-bold text-[#111827]">Applications</h2>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($applications as $application)
                    @php($theme = $applicationTheme($application))
                    @php($capacity = $totals['consultations'] ? round(($applicationCounts[$application] / max(1, $totals['consultations'])) * 100) : 0)
                    <article class="rounded-lg border border-[#E5E7EB] p-4 {{ $theme['theme'] }}">
                        <div class="flex items-center gap-4">
                            <div class="grid h-14 w-14 shrink-0 place-items-center rounded-lg {{ $theme['iconClass'] }}">
                                <i data-lucide="{{ $theme['icon'] }}" class="h-7 w-7"></i>
                            </div>
                            <div>
                                <div class="text-sm font-bold text-[#111827]">{{ $theme['label'] }}</div>
                                <div class="mt-1 text-2xl font-bold text-[#111827]">{{ $applicationCounts[$application] }} <span class="text-sm font-semibold text-gray-500">bookings</span></div>
                                <div class="mt-2 text-sm font-bold {{ $theme['textClass'] }}">${{ number_format(($applicationRevenue[$application] ?? 0) / 100, 2) }} <span class="font-semibold text-gray-500">revenue</span></div>
                            </div>
                        </div>
                        <div class="mt-4 h-2 rounded-full bg-white/70">
                            <div class="progress-fill {{ $theme['progress'] }}" style="width: {{ $capacity }}%"></div>
                        </div>
                        <div class="mt-2 text-xs font-bold text-[#111827]">{{ $capacity }}% of capacity</div>
                        <a class="mt-4 flex h-10 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white text-sm font-bold {{ $theme['textClass'] }}" href="{{ route('admin.consultations.index', ['application' => $application]) }}">Quick View</a>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="pie-chart" class="h-5 w-5 text-gray-500"></i>
                    <h2 class="text-lg font-bold text-[#111827]">Payment Mix</h2>
                </div>
                <a class="rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index') }}">View report</a>
            </div>
            <div class="grid items-center gap-6 md:grid-cols-[1fr_180px]">
                <div class="space-y-4">
                    @foreach($paymentRows as $status => $row)
                        @php($theme = $statusTheme($status))
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3 text-sm font-bold">
                                <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $theme['progress'] }}"></span>{{ $row['label'] }}</span>
                                <span>{{ $row['percent'] }}% ({{ $row['count'] }})</span>
                            </div>
                            <div class="h-2 rounded-full bg-[#EEF2F7]">
                                <div class="progress-fill {{ $theme['progress'] }}" style="width: {{ $row['percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="payment-mix-chart mx-auto grid h-40 w-40 place-items-center rounded-full" style="{{ $paymentMixStyle }}">
                    <div class="grid h-24 w-24 place-items-center rounded-full bg-white text-center">
                        <div class="text-3xl font-bold text-[#111827]">{{ $paymentMixTotal }}</div>
                        <div class="-mt-5 text-xs font-bold text-gray-500">Total</div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <section class="mt-5 overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E7EB] px-5 py-4">
            <div class="flex items-center gap-2 font-bold text-[#111827]"><i data-lucide="clipboard-list" class="h-5 w-5 text-gray-500"></i>Recent Consultations</div>
            <a class="rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.index') }}">View all consultations</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="bg-[#F7F8FC] text-xs font-bold text-gray-500">
                    <tr>
                        <th class="px-5 py-3">Client</th>
                        {{-- <th class="px-5 py-3">Application</th> --}}
                        <th class="px-5 py-3">Consultation Type</th>
                        <th class="px-5 py-3">Date & Time</th>
                        <th class="px-5 py-3">Amount</th>
                        <th class="px-5 py-3">Status</th>
                        {{-- <th class="px-5 py-3">Payment</th> --}}

                        <th class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB]">
                    @forelse($recent as $consultation)
                        @php($app = $applicationTheme($consultation->application))
                        @php($status = $statusTheme($consultation->status))
                        @php($payment = $statusTheme($consultation->payment_status))
                        @php($name = trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet')
                        @php($initial = Str::substr($name, 0, 1))
                        <tr class="hover:bg-[#FAFAFB]">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold {{ $app['theme'] }}">{{ $initial }}</div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-[#111827]">{{ $name }}</div>
                                        <div class="truncate text-xs font-semibold text-gray-500">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                                    </div>
                                </div>
                            </td>
                            {{-- <td class="px-5 py-4"><span class="inline-flex items-center gap-2 {{ $app['textClass'] }}"><i data-lucide="{{ $app['icon'] }}" class="h-4 w-4"></i>{{ $app['label'] }}</span></td> --}}
                            <td class="px-5 py-4 {{ $app['textClass'] }}">{{ $consultation->type->name }}</td>
                            <td class="px-5 py-4">{{ $consultation->starts_at?->format('M d, Y') ?? 'Not scheduled' }}<div class="text-xs text-gray-500">{{ $consultation->starts_at?->format('g:i A') ?? '' }}</div></td>
                            <td class="px-5 py-4 font-bold">${{ number_format($consultation->total_amount_cents / 100, 2) }}</td>
                            <td class="px-5 py-4"><span class="status-badge {{ $status['badge'] }}">{{ $status['label'] }}</span></td>
                            {{-- <td class="px-5 py-4"><span class="status-badge {{ $payment['badge'] }}">{{ $payment['label'] }}</span></td> --}}

                            <td class="px-5 py-4"><a class="grid h-9 w-9 place-items-center rounded-lg hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.show', $consultation) }}" aria-label="Open consultation"><i data-lucide="eye" class="h-5 w-5">View</i></a></td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-gray-500" colspan="8">No consultations yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-admin.layout>
