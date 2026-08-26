<x-admin.layout heading="Settings" subheading="Manage admin notification preferences.">
    <form class="max-w-3xl rounded-2xl border border-[#E5E7EB] bg-white p-6 shadow-[0_10px_30px_rgba(17,24,39,0.04)]" method="post" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('put')

        <div class="grid gap-5">
            <label class="flex items-start gap-3 rounded-lg border border-[#E5E7EB] bg-[#F7F8FC] p-4">
                <input class="mt-1 h-5 w-5 rounded border-[#E5E7EB]" type="checkbox" name="new_consultation_notifications_enabled" value="1" @checked(old('new_consultation_notifications_enabled', $notificationSettings['enabled']))>
                <span>
                    <span class="block text-sm font-bold text-[#111827]">Send admin consultation notifications</span>
                    <span class="mt-1 block text-xs font-semibold text-gray-500">Draft consultations are ignored. Emails are sent when a request is completed/submitted and when an existing consultation is rescheduled.</span>
                </span>
            </label>

            <label class="grid gap-2">
                <span class="text-sm font-bold text-[#111827]">Notification Recipients</span>
                <textarea class="min-h-36 rounded-lg border border-[#E5E7EB] px-3 py-3 text-sm font-semibold text-[#111827]" name="new_consultation_notification_emails" placeholder="admin@example.com&#10;manager@example.com">{{ old('new_consultation_notification_emails', implode(PHP_EOL, $notificationSettings['emails'])) }}</textarea>
                <span class="text-xs font-semibold text-gray-500">Add one email per line, or separate emails with commas.</span>
                @error('new_consultation_notification_emails')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
                @error('emails.*')<span class="text-sm font-bold text-[#B91C1C]">{{ $message }}</span>@enderror
            </label>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button class="admin-brand-button inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-bold" type="submit">Save Settings</button>
            <a class="inline-flex h-11 items-center justify-center rounded-lg border border-[#E5E7EB] bg-white px-5 text-sm font-bold text-[#111827] hover:bg-[#F7F8FC]" href="{{ route('admin.dashboard') }}">Cancel</a>
        </div>
    </form>
</x-admin.layout>
