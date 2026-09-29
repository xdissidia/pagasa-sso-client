<?php

namespace Pagasa\Sso\Tests\Feature;

use Pagasa\Sso\Tests\TestCase;

class LoginRouteTest extends TestCase
{
    public function test_it_starts_sign_in(): void
    {
        $this->assertStringStartsWith(
            'https://sso.test/sso/authorize?',
            $this->get('/sso/login')->headers->get('Location')
        );
    }

    public function test_prompt_login_starts_a_step_up(): void
    {
        $this->actingAs($this->makeUser());

        $this->assertStringContainsString('prompt=login', $this->get('/sso/login?prompt=login')->headers->get('Location'));
    }

    public function test_a_signed_in_user_is_sent_home(): void
    {
        $this->actingAs($this->makeUser())->get('/sso/login')->assertRedirect('http://localhost');
    }

    public function test_it_is_a_404_when_sso_is_disabled(): void
    {
        config(['sso.enabled' => false]);

        $this->get('/sso/login')->assertNotFound();
    }

    public function test_the_button_links_to_it(): void
    {
        $html = view('sso::button', ['light' => true, 'divider' => 'or'])->render();

        $this->assertStringContainsString('href="http://localhost/sso/login"', $html);
        $this->assertStringContainsString('pagasa-sso-btn--light', $html);
        $this->assertStringContainsString('https://sso.test/images/pagasa-logo-64.png', $html);
        $this->assertStringContainsString('pagasa-sso-divider', $html);
    }
}
