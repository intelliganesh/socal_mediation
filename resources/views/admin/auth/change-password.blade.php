<x-admin.layout heading="Change Password" subheading="Update the password used to sign in to your account.">
    <form class="max-w-2xl rounded-lg border border-[#E5E7EB] bg-white p-6 shadow-[0_10px_30px_rgba(17,24,39,0.04)]" method="post" action="{{ route('admin.password.update') }}">
        @csrf
        @method('put')

        <div class="grid gap-5">
            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Current Password</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold" type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">New Password</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold" type="password" name="password" autocomplete="new-password" required>
                <span class="text-xs font-semibold text-gray-500">Minimum 8 characters.</span>
            </label>
            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Confirm New Password</span>
                <input class="h-11 rounded-lg border border-[#E5E7EB] px-3 text-sm font-semibold" type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
        </div>

        <button class="admin-brand-button mt-6 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-bold" type="submit">Update Password</button>
    </form>
</x-admin.layout>
