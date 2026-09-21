<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebsiteForm;
use Illuminate\Http\Request;

class WebsiteFormAdminController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $selectedApplication = $user->isGlobalAdmin()
            ? $request->query('application')
            : $user->application;

        $websiteForms = WebsiteForm::query()
            ->when($selectedApplication, fn ($query, $application) => $query->where('application', $application))
            ->when($request->query('q'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.website-forms.index', compact('websiteForms', 'selectedApplication'));
    }

    public function show(Request $request, WebsiteForm $websiteForm)
    {
        abort_unless($request->user()->canAccessApplication($websiteForm->application), 403);

        return view('admin.website-forms.show', compact('websiteForm'));
    }
}
