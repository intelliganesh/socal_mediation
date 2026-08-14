<x-admin.layout :heading="$mode === 'create' ? 'Add User' : 'Edit User'" subheading="Assign admin access to all applications or one application.">
    <form class="max-w-3xl rounded-2xl border border-[#E5E7EB] bg-white p-6 shadow-[0_10px_30px_rgba(17,24,39,0.04)]" method="post" action="{{ $mode === 'create' ? route('admin.users.store') : route('admin.users.update', $user) }}">
        @csrf
        @if($mode === 'edit')
            @method('put')
        @endif

        <div class="grid gap-5">
            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Name</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold text-[#111827]" name="name" value="{{ old('name', $user->name) }}" required>
                @error('name')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>

            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Email</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold text-[#111827]" type="email" name="email" value="{{ old('email', $user->email) }}" @readonly($user->email === 'admin@socal.test') required>
                @if($user->email === 'admin@socal.test')
                    <span class="text-xs font-semibold text-gray-500">Primary admin email is retained for global access.</span>
                @endif
                @error('email')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>

            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Password</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold text-[#111827]" type="password" name="password" @required($mode === 'create')>
                <span class="text-xs font-semibold text-gray-500">{{ $mode === 'create' ? 'Minimum 8 characters.' : 'Leave blank to keep the current password.' }}</span>
                @error('password')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>

            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Application Access</span>
                <select class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold text-[#111827]" name="application" @disabled($user->email === 'admin@socal.test')>
                    <option value="" @selected(old('application', $user->application) === null)>All Applications</option>
                    <option value="socal" @selected(old('application', $user->application) === 'socal')>SoCal Mediation Center</option>
                    <option value="legal" @selected(old('application', $user->application) === 'legal')>Legal Consultation</option>
                </select>
                @if($user->email === 'admin@socal.test')
                    <input type="hidden" name="application" value="">
                @endif
                @error('application')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button class="inline-flex h-11 items-center justify-center rounded-lg bg-[#082BC3] px-5 text-sm font-bold text-white hover:bg-[#111827]" type="submit">
                {{ $mode === 'create' ? 'Create User' : 'Update User' }}
            </button>
            <a class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white px-5 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.users.index') }}">Cancel</a>
        </div>
    </form>
</x-admin.layout>
