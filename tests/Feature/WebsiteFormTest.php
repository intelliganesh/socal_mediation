<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_stores_a_website_form_submission(): void
    {
        $response = $this->postJson('/api/v1/website-forms', [
            'application' => 'socal',
            'name' => 'Jordan Smith',
            'email' => 'jordan@example.com',
            'phone' => '+1 555 010 0200',
            'message' => 'I would like more information about mediation.',
            'extra_fields' => [
                'preferred_contact_method' => 'email',
                'company' => 'Example LLC',
                'interests' => ['mediation', 'consultation'],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Your message has been submitted successfully.')
            ->assertJsonPath('data.application', 'socal')
            ->assertJsonPath('data.name', 'Jordan Smith')
            ->assertJsonPath('data.extra_fields.company', 'Example LLC');

        $this->assertDatabaseHas('website_form', [
            'application' => 'socal',
            'name' => 'Jordan Smith',
            'email' => 'jordan@example.com',
            'phone' => '+1 555 010 0200',
            'message' => 'I would like more information about mediation.',
        ]);

        $this->assertSame(
            ['mediation', 'consultation'],
            WebsiteForm::firstOrFail()->extra_fields['interests'],
        );
    }

    public function test_public_api_validates_required_fields_and_application(): void
    {
        $this->postJson('/api/v1/website-forms', [
            'application' => 'unknown',
            'name' => '',
            'email' => 'not-an-email',
            'phone' => '',
            'message' => '',
            'extra_fields' => 'not-an-object',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'application',
                'name',
                'email',
                'phone',
                'message',
                'extra_fields',
            ]);
    }

    public function test_global_admin_can_filter_and_view_website_forms(): void
    {
        $admin = $this->admin();
        $socal = WebsiteForm::create([
            'application' => 'socal',
            'name' => 'SoCal Contact',
            'email' => 'socal@example.com',
            'phone' => '111-111-1111',
            'message' => 'Private SoCal message',
            'extra_fields' => ['case_type' => 'Property dispute'],
        ]);
        WebsiteForm::create([
            'application' => 'legal',
            'name' => 'Legal Contact',
            'email' => 'legal@example.com',
            'phone' => '222-222-2222',
            'message' => 'Private legal message',
            'extra_fields' => ['county' => 'Los Angeles'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.website-forms.index', ['application' => 'socal']))
            ->assertOk()
            ->assertSee('SoCal Contact')
            ->assertSee('socal@example.com')
            ->assertSee('111-111-1111')
            ->assertDontSee('Legal Contact')
            ->assertDontSee('Private SoCal message')
            ->assertDontSee('Property dispute');

        $this->actingAs($admin)
            ->get(route('admin.website-forms.show', $socal))
            ->assertOk()
            ->assertSee('SoCal Contact')
            ->assertSee('Private SoCal message')
            ->assertSee('Case Type')
            ->assertSee('Property dispute');
    }

    public function test_application_admin_only_accesses_its_website_forms(): void
    {
        $socalAdmin = $this->admin('socal');
        $socal = WebsiteForm::create([
            'application' => 'socal',
            'name' => 'Visible Contact',
            'email' => 'visible@example.com',
            'phone' => '111-111-1111',
            'message' => 'Visible message',
        ]);
        $legal = WebsiteForm::create([
            'application' => 'legal',
            'name' => 'Hidden Contact',
            'email' => 'hidden@example.com',
            'phone' => '222-222-2222',
            'message' => 'Hidden message',
        ]);

        $this->actingAs($socalAdmin)
            ->get(route('admin.website-forms.index', ['application' => 'legal']))
            ->assertOk()
            ->assertSee('Visible Contact')
            ->assertDontSee('Hidden Contact');

        $this->actingAs($socalAdmin)
            ->get(route('admin.website-forms.show', $socal))
            ->assertOk();

        $this->actingAs($socalAdmin)
            ->get(route('admin.website-forms.show', $legal))
            ->assertForbidden();
    }

    private function admin(?string $application = null): User
    {
        return User::create([
            'name' => $application ? 'Application Admin' : 'Global Admin',
            'email' => ($application ?: 'global').'.admin@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'application' => $application,
        ]);
    }
}
