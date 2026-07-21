<x-admin.layout heading="Booking Calendar" subheading="Current month plus next 3 months, with manual Outlook sync.">
    @php
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['bg' => '#E8DDE1', 'text' => '#75172E', 'bar' => '#75172E']
            : ['bg' => '#F1F6FE', 'text' => '#082BC3', 'bar' => '#082BC3'];
    @endphp

    <div class="mb-5 grid gap-3 rounded-xl border border-[#E5E7EB] bg-white p-4 sm:flex sm:flex-wrap sm:items-center sm:justify-between">
        <form class="grid gap-3 sm:flex sm:flex-wrap" method="get">
            <select class="w-full rounded-lg border border-[#E5E7EB] bg-white px-4 py-2 text-sm font-semibold sm:w-auto" name="application" onchange="this.form.submit()">
                <option value="">All Applications</option>
                <option value="socal" @selected($selectedApplication === 'socal')>SoCal Mediation Center</option>
                <option value="legal" @selected($selectedApplication === 'legal')>Legal Consultation</option>
            </select>
            <select class="w-full rounded-lg border border-[#E5E7EB] bg-white px-4 py-2 text-sm font-semibold sm:w-auto" name="month" onchange="this.form.submit()">
                @foreach($months as $month)
                    <option value="{{ $month->format('Y-m') }}" @selected($selectedMonth->format('Y-m') === $month->format('Y-m'))>{{ $month->format('F Y') }}</option>
                @endforeach
            </select>
        </form>
        <form method="post" action="{{ route('admin.calendar.sync') }}">
            @csrf
            <button class="w-full rounded-lg bg-[#111827] px-4 py-2 text-sm font-bold text-white sm:w-auto">Sync Outlook</button>
        </form>
    </div>

    <div class="grid gap-3 md:hidden">
        @php($groupedConsultations = $consultations->groupBy(fn($item) => $item->starts_at?->toDateString()))
        @forelse($groupedConsultations as $date => $items)
            <section class="rounded-xl border border-[#E5E7EB] bg-white p-4">
                <div class="font-bold">{{ \Carbon\CarbonImmutable::parse($date)->format('M d, Y') }}</div>
                <div class="mt-3 grid gap-2">
                    @foreach($items as $consultation)
                        @php($theme = $applicationTheme($consultation->application))
                        <a class="rounded-lg border-l-4 p-3 text-sm font-bold" style="background: {{ $theme['bg'] }}; color: {{ $theme['text'] }}; border-color: {{ $theme['bar'] }}" href="{{ route('admin.consultations.show', $consultation) }}">
                            <span class="block">{{ $consultation->starts_at->format('g:i A') }}</span>
                            <span class="mt-1 block">{{ $consultation->type->name }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-[#E5E7EB] bg-white px-4 py-10 text-center text-sm text-gray-500">No consultations scheduled for this month.</div>
        @endforelse
    </div>

    <div class="hidden grid-cols-7 overflow-hidden rounded-xl border border-[#E5E7EB] bg-white text-sm md:grid">
        @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)
            <div class="border-b border-[#E5E7EB] bg-[#F7F8FC] px-3 py-3 text-xs font-bold uppercase tracking-wide text-gray-500">{{ $day }}</div>
        @endforeach
        @for($i = 0; $i < $selectedMonth->dayOfWeek; $i++)
            <div class="min-h-32 border-b border-r border-[#E5E7EB] bg-gray-50"></div>
        @endfor
        @for($day = 1; $day <= $selectedMonth->daysInMonth; $day++)
            @php($date = $selectedMonth->setDay($day))
            <div class="min-h-32 border-b border-r border-[#E5E7EB] p-3">
                <div class="mb-2 font-bold">{{ $day }}</div>
                @foreach($consultations->filter(fn($item) => $item->starts_at?->toDateString() === $date->toDateString()) as $consultation)
                    @php($theme = $applicationTheme($consultation->application))
                    <a class="mb-2 block rounded-lg border-l-4 p-2 text-xs font-bold" style="background: {{ $theme['bg'] }}; color: {{ $theme['text'] }}; border-color: {{ $theme['bar'] }}" href="{{ route('admin.consultations.show', $consultation) }}">
                        <span class="block">{{ $consultation->starts_at->format('g:i A') }}</span>
                        <span class="block truncate">{{ $consultation->type->name }}</span>
                    </a>
                @endforeach
            </div>
        @endfor
    </div>
</x-admin.layout>
