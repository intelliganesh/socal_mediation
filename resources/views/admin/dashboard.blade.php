<x-admin.layout heading="Dashboard" subheading="Useful operational metrics across both applications.">
    @php
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['label' => 'Legal Consultation', 'dot' => '#75172E', 'bg' => '#E8DDE1', 'text' => '#75172E']
            : ['label' => 'SoCal Mediation Center', 'dot' => '#082BC3', 'bg' => '#F1F6FE', 'text' => '#082BC3'];

        $statusTheme = function (?string $status) {
            return match ($status) {
                'paid', 'not_required', 'scheduled' => ['bg' => '#BBF7D0', 'text' => '#166534', 'bar' => '#22C55E'],
                'cancelled', 'failed' => ['bg' => '#F8EEF1', 'text' => '#B91C1C', 'bar' => '#B91C1C'],
                'error' => ['bg' => '#FEE2E2', 'text' => '#EF4444', 'bar' => '#EF4444'],
                'partially_paid' => ['bg' => '#FEF3C7', 'text' => '#92400E', 'bar' => '#F59E0B'],
                'pending', 'pending_payment' => ['bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3'],
                default => ['bg' => '#F3F4F6', 'text' => '#4B5563', 'bar' => '#9CA3AF'],
            };
        };
    @endphp

    <div class="grid gap-0 overflow-hidden rounded-xl border border-[#E5E7EB] bg-white md:grid-cols-4">
        @foreach([
            'Consultations' => $totals['consultations'],
            'Drafts' => $totals['drafts'],
            'Scheduled' => $totals['scheduled'],
            'Revenue' => '$'.number_format($totals['revenue_cents'] / 100, 2),
        ] as $label => $value)
            <div class="border-b border-[#E5E7EB] p-5 md:border-b-0 md:border-r last:border-r-0">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500">{{ $label }}</div>
                <div class="mt-3 text-2xl font-bold tracking-tight">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="rounded-xl border border-[#E5E7EB] bg-white">
            <div class="border-b border-[#E5E7EB] px-6 py-5">
                <h2 class="text-lg font-bold">Applications</h2>
                <p class="mt-1 text-sm text-gray-500">Shared firm database, separated frontend flows</p>
            </div>
            <div class="space-y-4 p-6">
                @foreach(['socal', 'legal'] as $application)
                    @php($theme = $applicationTheme($application))
                    <a class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#E5E7EB] bg-white p-4 hover:bg-[#FAFAFB]" href="{{ route('admin.consultations.index', ['application' => $application]) }}">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 shrink-0 rounded-full" style="background: {{ $theme['dot'] }}"></span>
                                <span class="font-bold">{{ $theme['label'] }}</span>
                            </div>
                            <div class="mt-3 text-sm text-gray-500">{{ $applicationCounts[$application] }} bookings - {{ ucfirst($application) }}</div>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $theme['bg'] }}; color: {{ $theme['text'] }}">View</span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-[#E5E7EB] bg-white p-6">
            <h2 class="text-lg font-bold">Payment Mix</h2>
            <p class="mt-1 text-sm text-gray-500">Quick scan of booking payment states</p>
            <div class="mt-6 space-y-4">
                @foreach(['paid' => 'Paid', 'pending' => 'Pending', 'partially_paid' => 'Partial', 'not_started' => 'Not started'] as $status => $label)
                    @php($count = $recent->where('payment_status', $status)->count())
                    @php($theme = $statusTheme($status))
                    <div>
                        <div class="mb-2 flex justify-between text-sm font-bold"><span>{{ $label }}</span><span>{{ $count }}</span></div>
                        <div class="h-2 rounded-full bg-[#EEF2F7]">
                            <div class="h-2 rounded-full" style="width: {{ $recent->count() ? ($count / $recent->count()) * 100 : 0 }}%; background: {{ $theme['bar'] }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="mt-5 rounded-xl border border-[#E5E7EB] bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#E5E7EB] px-6 py-4">
            <div class="font-bold">Recent Consultations</div>
            <a class="rounded-lg border border-[#E5E7EB] px-3 py-2 text-sm font-bold hover:bg-gray-50" href="{{ route('admin.consultations.index') }}">View all</a>
        </div>
        <div class="divide-y divide-[#E5E7EB]">
            @forelse($recent as $consultation)
                @php($app = $applicationTheme($consultation->application))
                @php($status = $statusTheme($consultation->payment_status))
                <a class="grid gap-4 px-6 py-4 text-sm hover:bg-[#FAFAFB] md:grid-cols-5" href="{{ route('admin.consultations.show', $consultation) }}">
                    <span class="font-bold">{{ $consultation->booking_number }}</span>
                    <span><span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $app['label'] }}</span></span>
                    <span>{{ $consultation->type->name }}</span>
                    <span><span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $status['bg'] }}; color: {{ $status['text'] }}">{{ str_replace('_', ' ', ucfirst($consultation->payment_status)) }}</span></span>
                    <span class="font-bold md:text-right">${{ number_format($consultation->total_amount_cents / 100, 2) }}</span>
                </a>
            @empty
                <div class="px-5 py-10 text-center text-sm text-gray-500">No consultations yet.</div>
            @endforelse
        </div>
    </div>
</x-admin.layout>
