<x-admin.layout heading="Website Forms" subheading="Review messages submitted through both websites." :application="$selectedApplication">
    @php
        $currentUser = auth()->user();
        $applicationTheme = fn (string $application) => $application === 'legal'
            ? ['label' => 'Law Office', 'class' => 'app-theme-legal']
            : ['label' => 'SoCal Mediation Center', 'class' => 'app-theme-socal'];
    @endphp

    <form class="mb-5 rounded-lg border border-[#E5E7EB] bg-white p-5 shadow-[0_10px_30px_rgba(17,24,39,0.04)]" method="get">
        <div class="grid gap-3 md:grid-cols-[minmax(240px,1fr)_220px_auto_auto] md:items-center">
            <label class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500"></i>
                <input class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white pl-10 pr-3 text-sm font-semibold text-[#111827] placeholder:text-gray-500" type="search" name="q" value="{{ request('q') }}" placeholder="Search name, email, or phone...">
            </label>

            @if($currentUser?->isGlobalAdmin())
                <select class="h-11 w-full rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-semibold text-[#111827]" name="application">
                    <option value="">All Applications</option>
                    <option value="socal" @selected($selectedApplication === 'socal')>SoCal Mediation Center</option>
                    <option value="legal" @selected($selectedApplication === 'legal')>Law Office</option>
                </select>
            @else
                @php($assignedApplication = $applicationTheme($selectedApplication))
                <div class="flex h-11 items-center rounded-lg border border-[#E5E7EB] bg-white px-3 text-sm font-bold text-[#111827]">{{ $assignedApplication['label'] }}</div>
            @endif

            <a class="flex h-11 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-semibold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.website-forms.index') }}">Reset</a>
            <button class="admin-brand-button h-11 rounded-lg px-5 text-sm font-bold" type="submit">Apply</button>
        </div>
    </form>

    <section class="overflow-hidden rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="grid divide-y divide-[#E5E7EB] md:hidden">
            @forelse($websiteForms as $websiteForm)
                @php($application = $applicationTheme($websiteForm->application))
                <article class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate font-bold text-[#111827]">{{ $websiteForm->name }}</div>
                            <div class="mt-1 truncate text-sm text-gray-500">{{ $websiteForm->email }}</div>
                            <div class="mt-1 text-sm text-gray-500">{{ $websiteForm->phone }}</div>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold {{ $application['class'] }}">{{ $application['label'] }}</span>
                    </div>
                    <div class="mt-3 text-xs font-semibold text-gray-500">Submitted {{ $websiteForm->created_at->format('M d, Y g:i A') }}</div>
                    <a class="mt-4 flex h-10 items-center justify-center gap-2 rounded-lg border border-[#E5E7EB] font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.website-forms.show', $websiteForm) }}">
                        <i data-lucide="eye" class="h-4 w-4"></i>
                        View details
                    </a>
                </article>
            @empty
                <div class="px-4 py-10 text-center text-gray-500">No website forms found.</div>
            @endforelse
        </div>

        <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead class="text-xs font-bold text-gray-500">
                    <tr class="border-b border-[#E5E7EB]">
                        <th class="px-5 py-4">Name</th>
                        <th class="px-5 py-4">Email</th>
                        <th class="px-5 py-4">Phone</th>
                        <th class="px-5 py-4">Application</th>
                        <th class="px-5 py-4">Submitted</th>
                        <th class="px-5 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB]">
                    @forelse($websiteForms as $websiteForm)
                        @php($application = $applicationTheme($websiteForm->application))
                        <tr class="hover:bg-[#FAFAFB]">
                            <td class="px-5 py-4 font-bold text-[#111827]">{{ $websiteForm->name }}</td>
                            <td class="px-5 py-4"><a class="font-semibold text-[#111827] hover:underline" href="mailto:{{ $websiteForm->email }}">{{ $websiteForm->email }}</a></td>
                            <td class="px-5 py-4"><a class="font-semibold text-[#111827] hover:underline" href="tel:{{ $websiteForm->phone }}">{{ $websiteForm->phone }}</a></td>
                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $application['class'] }}">{{ $application['label'] }}</span></td>
                            <td class="px-5 py-4 font-semibold text-gray-500">{{ $websiteForm->created_at->format('M d, Y') }}<br>{{ $websiteForm->created_at->format('g:i A') }}</td>
                            <td class="px-5 py-4">
                                <a class="ml-auto grid h-11 w-11 place-items-center rounded-lg border border-[#E5E7EB] text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.website-forms.show', $websiteForm) }}" aria-label="Open website form">
                                    <i data-lucide="eye" class="h-5 w-5"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-gray-500" colspan="6">No website forms found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-5 rounded-lg border border-[#E5E7EB] bg-white px-5 py-4 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm font-semibold text-gray-500">Showing {{ $websiteForms->firstItem() ?? 0 }} to {{ $websiteForms->lastItem() ?? 0 }} of {{ $websiteForms->total() }} results</div>
            <div>{{ $websiteForms->links() }}</div>
        </div>
    </div>
</x-admin.layout>
