<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminNewConsultationNotificationService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit(AdminNewConsultationNotificationService $notifications)
    {
        $this->authorizeGlobalAdmin();

        return view('admin.settings.edit', [
            'notificationSettings' => $notifications->settings(),
        ]);
    }

    public function update(Request $request, AdminNewConsultationNotificationService $notifications)
    {
        $this->authorizeGlobalAdmin();

        $data = $request->validate([
            'new_consultation_notifications_enabled' => ['nullable', 'boolean'],
            'new_consultation_notification_emails' => ['nullable', 'string'],
        ]);

        $emails = collect(preg_split('/[\r\n,]+/', (string) ($data['new_consultation_notification_emails'] ?? '')))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        validator(
            ['emails' => $emails],
            ['emails.*' => ['email', 'max:255']]
        )->validate();

        $notifications->save(
            $request->boolean('new_consultation_notifications_enabled'),
            $emails
        );

        return redirect()->route('admin.settings.edit')->with('status', 'Settings updated.');
    }

    private function authorizeGlobalAdmin(): void
    {
        abort_unless(auth()->user()?->isGlobalAdmin(), 403);
    }
}
