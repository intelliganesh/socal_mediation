<x-admin.layout heading="Consultations" subheading="Filter and review bookings from both application flows.">
    @php
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['label' => 'Legal Consultation', 'bg' => '#E8DDE1', 'text' => '#75172E', 'bar' => '#75172E']
            : ['label' => 'SoCal Mediation Center', 'bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3'];

        $statusTheme = function (?string $status) {
            return match ($status) {
                'paid', 'not_required', 'scheduled' => ['bg' => '#BBF7D0', 'text' => '#166534', 'bar' => '#22C55E', 'label' => 'Paid'],
                'cancelled', 'failed' => ['bg' => '#F8EEF1', 'text' => '#B91C1C', 'bar' => '#B91C1C', 'label' => 'Cancelled'],
                'error' => ['bg' => '#FEE2E2', 'text' => '#EF4444', 'bar' => '#EF4444', 'label' => 'Error'],
                'partially_paid' => ['bg' => '#FEF3C7', 'text' => '#92400E', 'bar' => '#F59E0B', 'label' => 'Partial'],
                'pending', 'pending_payment' => ['bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3', 'label' => 'Pending'],
                default => ['bg' => '#F3F4F6', 'text' => '#4B5563', 'bar' => '#9CA3AF', 'label' => str_replace('_', ' ', ucfirst((string) $status))],
            };
        };
    @endphp

    <form class="mb-5 grid gap-3 rounded-xl border border-[#E5E7EB] bg-white p-4 sm:flex sm:flex-wrap" method="get">
        <select class="w-full rounded-lg border border-[#E5E7EB] bg-white px-3 py-2 text-sm font-semibold sm:w-auto" name="application">
            <option value="">All applications</option>
            <option value="socal" @selected(request('application') === 'socal')>Socal mediation</option>
            <option value="legal" @selected(request('application') === 'legal')>Legal consultation</option>
        </select>
        <select class="w-full rounded-lg border border-[#E5E7EB] bg-white px-3 py-2 text-sm font-semibold sm:w-auto" name="status">
            <option value="">All statuses</option>
            @foreach(['details_complete', 'scheduled', 'pending_payment', 'partially_paid', 'paid', 'cancelled', 'failed', 'error'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
            @endforeach
        </select>
        <button class="w-full rounded-lg bg-[#111827] px-5 py-2 text-sm font-bold text-white sm:w-auto">Apply</button>
    </form>

    <div class="overflow-hidden rounded-xl border border-[#E5E7EB] bg-white">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#E5E7EB] px-5 py-4">
            <h2 class="text-lg font-bold">All Consultations</h2>
            <span class="text-sm font-semibold text-gray-500">{{ $consultations->total() }} records</span>
        </div>
        <div class="grid divide-y divide-[#E5E7EB] md:hidden">
            @forelse($consultations as $consultation)
                @php($app = $applicationTheme($consultation->application))
                @php($status = $statusTheme($consultation->payment_status))
                @php($payerCount = max(1, $consultation->paymentRequests->count()))
                @php($paidCount = $consultation->paymentRequests->where('status', 'paid')->count())
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-bold">{{ $consultation->booking_number }}</div>
                            <div class="mt-1 truncate text-sm text-gray-500">{{ $consultation->type->name }}</div>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $status['bg'] }}; color: {{ $status['text'] }}">{{ $status['label'] }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $app['label'] }}</span>
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-gray-500">Client</dt>
                            <dd class="mt-1 font-bold">{{ trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet' }}</dd>
                            <dd class="mt-1 break-all text-gray-500">{{ $consultation->primary_email ?: 'No email yet' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-gray-500">Schedule</dt>
                            <dd class="mt-1 font-bold">{{ $consultation->starts_at?->format('M d, Y') ?? 'Not scheduled' }}</dd>
                            <dd class="mt-1 text-gray-500">{{ $consultation->starts_at?->format('g:i A') ?? $consultation->timezone }} {{ $consultation->starts_at ? $consultation->timezone : '' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-gray-500">Payment</dt>
                            <dd class="mt-1 font-bold text-[#082BC3]">${{ number_format($consultation->total_amount_cents / 100, 2) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                            <span class="text-gray-500">{{ $payerCount }} {{ Str::plural('payer', $payerCount) }}</span>
                            <span class="font-bold">{{ $paidCount }} Paid</span>
                        </div>
                        <div class="h-2 rounded-full bg-[#EEF2F7]">
                            <div class="h-2 rounded-full" style="width: {{ $payerCount ? ($paidCount / $payerCount) * 100 : 0 }}%; background: {{ $status['bar'] }}"></div>
                        </div>
                    </div>
                    <a class="mt-4 block rounded-lg border border-[#E5E7EB] px-4 py-2 text-center font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.show', $consultation) }}">Review</a>
                </article>
            @empty
                <div class="px-4 py-10 text-center text-gray-500">No consultations found.</div>
            @endforelse
        </div>
        <table class="hidden w-full text-left text-sm md:table">
            <thead class="bg-[#F7F8FC] text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Booking</th>
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Schedule</th>
                    <th class="px-4 py-3">Payment</th>
                    <th class="px-4 py-3">Progress</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E7EB]">
                @forelse($consultations as $consultation)
                    @php($app = $applicationTheme($consultation->application))
                    @php($status = $statusTheme($consultation->payment_status))
                    @php($payerCount = max(1, $consultation->paymentRequests->count()))
                    @php($paidCount = $consultation->paymentRequests->where('status', 'paid')->count())
                    <tr class="align-middle hover:bg-[#FAFAFB]">
                        <td class="px-4 py-5">
                            <div class="font-bold">{{ $consultation->booking_number }}</div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $app['bg'] }}; color: {{ $app['text'] }}">{{ $app['label'] }}</span>
                                <span class="text-gray-500">{{ $consultation->type->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-5">
                            <div class="font-bold">{{ trim($consultation->primary_first_name.' '.$consultation->primary_last_name) ?: 'No name yet' }}</div>
                            <div class="mt-1 text-gray-500">{{ $consultation->primary_email ?: 'No email yet' }}</div>
                        </td>
                        <td class="px-4 py-5">
                            <div class="font-bold">{{ $consultation->starts_at?->format('M d, Y') ?? 'Not scheduled' }}</div>
                            <div class="mt-1 text-gray-500">{{ $consultation->starts_at?->format('g:i A') ?? $consultation->timezone }} {{ $consultation->starts_at ? $consultation->timezone : '' }}</div>
                        </td>
                        <td class="px-4 py-5">
                            <span class="rounded-full px-3 py-1 text-xs font-bold" style="background: {{ $status['bg'] }}; color: {{ $status['text'] }}">{{ $status['label'] }}</span>
                            <div class="mt-2 font-bold text-[#082BC3]">${{ number_format($consultation->total_amount_cents / 100, 2) }}</div>
                        </td>
                        <td class="px-4 py-5">
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <span class="text-gray-500">{{ $payerCount }} {{ Str::plural('payer', $payerCount) }}</span>
                                <span class="font-bold">{{ $paidCount }} Paid</span>
                            </div>
                            <div class="h-2 rounded-full bg-[#EEF2F7]">
                                <div class="h-2 rounded-full" style="width: {{ $payerCount ? ($paidCount / $payerCount) * 100 : 0 }}%; background: {{ $status['bar'] }}"></div>
                            </div>
                        </td>
                        <td class="px-4 py-5 text-right"><a class="rounded-lg border border-[#E5E7EB] px-4 py-2 font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.consultations.show', $consultation) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-10 text-center text-gray-500" colspan="6">No consultations found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $consultations->links() }}</div>
</x-admin.layout>
