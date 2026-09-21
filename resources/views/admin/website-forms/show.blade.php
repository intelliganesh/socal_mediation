<x-admin.layout
    heading="Website Form Submission"
    subheading="Review the submitted contact information and message."
    :application="$websiteForm->application"
    breadcrumb="Website Form Details"
>
    @php
        $isLegal = $websiteForm->application === 'legal';
        $applicationLabel = $isLegal ? 'Law Office' : 'SoCal Mediation Center';
        $applicationClass = $isLegal ? 'app-theme-legal' : 'app-theme-socal';
    @endphp

    <div class="mb-5 flex justify-end">
        <a class="inline-flex h-11 items-center gap-2 rounded-lg border border-[#E5E7EB] bg-white px-4 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.website-forms.index') }}">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>
            Back to website forms
        </a>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.55fr)]">
        <section class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <header class="flex items-center gap-3 border-b border-[#E5E7EB] px-6 py-4">
                <i data-lucide="message-square-text" class="h-5 w-5"></i>
                <h2 class="text-base font-bold text-[#111827]">Message</h2>
            </header>
            <div class="whitespace-pre-wrap break-words px-6 py-5 text-sm leading-7 text-[#374151]">{{ $websiteForm->message }}</div>
        </section>

        <section class="rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
            <header class="flex items-center gap-3 border-b border-[#E5E7EB] px-6 py-4">
                <i data-lucide="contact" class="h-5 w-5"></i>
                <h2 class="text-base font-bold text-[#111827]">Contact Information</h2>
            </header>
            <dl class="grid gap-5 px-6 py-5 text-sm">
                <div>
                    <dt class="font-semibold text-gray-500">Name</dt>
                    <dd class="mt-1 font-bold text-[#111827]">{{ $websiteForm->name }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-500">Email</dt>
                    <dd class="mt-1"><a class="font-bold text-[#111827] hover:underline" href="mailto:{{ $websiteForm->email }}">{{ $websiteForm->email }}</a></dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-500">Phone</dt>
                    <dd class="mt-1"><a class="font-bold text-[#111827] hover:underline" href="tel:{{ $websiteForm->phone }}">{{ $websiteForm->phone }}</a></dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-500">Application</dt>
                    <dd class="mt-2"><span class="inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $applicationClass }}">{{ $applicationLabel }}</span></dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-500">Submitted</dt>
                    <dd class="mt-1 font-bold text-[#111827]">{{ $websiteForm->created_at->format('M d, Y g:i A') }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="mt-5 rounded-lg border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <header class="flex items-center gap-3 border-b border-[#E5E7EB] px-6 py-4">
            <i data-lucide="list-tree" class="h-5 w-5"></i>
            <h2 class="text-base font-bold text-[#111827]">Additional Information</h2>
        </header>
        <dl class="grid gap-x-8 gap-y-5 px-6 py-5 md:grid-cols-2">
            @forelse($websiteForm->extra_fields ?? [] as $field => $value)
                <div class="min-w-0 border-b border-[#E5E7EB] pb-4 last:border-0">
                    <dt class="text-sm font-semibold text-gray-500">{{ Str::headline((string) $field) }}</dt>
                    <dd class="mt-1 break-words text-sm font-bold text-[#111827]">
                        @if(is_array($value))
                            <pre class="overflow-x-auto whitespace-pre-wrap break-words font-sans">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @elseif(is_bool($value))
                            {{ $value ? 'Yes' : 'No' }}
                        @elseif($value === null || $value === '')
                            Not provided
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                </div>
            @empty
                <div class="text-sm text-gray-500">No additional information was submitted.</div>
            @endforelse
        </dl>
    </section>
</x-admin.layout>
