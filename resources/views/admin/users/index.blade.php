<x-admin.layout heading="Users" subheading="Manage admin access by application.">
    @php
        $applicationTheme = fn (?string $application) => $application === 'legal'
            ? ['label' => 'Legal Consultation', 'class' => 'app-theme-legal']
            : ($application === 'socal'
                ? ['label' => 'SoCal Mediation Center', 'class' => 'app-theme-socal']
                : ['label' => 'All Applications', 'class' => 'status-badge-paid']);
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm font-semibold text-gray-500">{{ $users->total() }} admin {{ Str::plural('user', $users->total()) }}</div>
        <a class="inline-flex h-11 items-center gap-2 rounded-lg bg-[#082BC3] px-4 text-sm font-bold text-white hover:bg-[#111827]" href="{{ route('admin.users.create') }}">
            <i data-lucide="user-plus" class="h-4 w-4"></i>
            Add User
        </a>
    </div>

    <section class="overflow-hidden rounded-2xl border border-[#E5E7EB] bg-white shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-[#F7F8FC] text-xs font-bold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-5 py-4">Name</th>
                        <th class="px-5 py-4">Email</th>
                        <th class="px-5 py-4">Application Access</th>
                        <th class="px-5 py-4">Role</th>
                        <th class="px-5 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB]">
                    @forelse($users as $user)
                        @php($theme = $applicationTheme($user->application))
                        <tr class="hover:bg-[#FAFAFB]">
                            <td class="px-5 py-4 font-bold text-[#111827]">{{ $user->name }}</td>
                            <td class="px-5 py-4 font-semibold text-gray-500">{{ $user->email }}</td>
                            <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold {{ $theme['class'] }}">{{ $theme['label'] }}</span></td>
                            <td class="px-5 py-4 font-semibold text-[#111827]">{{ Str::headline($user->role) }}</td>
                            <td class="px-5 py-4">
                                <a class="ml-auto grid h-10 w-10 place-items-center rounded-lg border border-[#E5E7EB] text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.users.edit', $user) }}" aria-label="Edit user">
                                    <i data-lucide="pencil" class="h-4 w-4"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-gray-500" colspan="5">No users found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-5 rounded-2xl border border-[#E5E7EB] bg-white px-5 py-4 shadow-[0_10px_30px_rgba(17,24,39,0.04)]">
        {{ $users->links() }}
    </div>
</x-admin.layout>
