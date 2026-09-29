<?php

namespace Pagasa\Sso\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pagasa\Sso\SsoClient;
use Pagasa\Sso\SsoException;
use Pagasa\Sso\Testing\SsoResponses;
use Pagasa\Sso\Tests\TestCase;

class SsoClientTest extends TestCase
{
    protected function sso(): SsoClient
    {
        return $this->app->make(SsoClient::class);
    }

    public function test_authorize_url_carries_client_redirect_uri_and_state(): void
    {
        $url = $this->sso()->authorizeUrl('st4te');

        $this->assertStringStartsWith('https://sso.test/sso/authorize?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'client_id' => 'demo',
            'redirect_uri' => 'http://localhost/sso/callback',
            'state' => 'st4te',
        ], $query);
    }

    public function test_authorize_url_adds_prompt_login_for_step_up(): void
    {
        parse_str((string) parse_url($this->sso()->authorizeUrl('s', true), PHP_URL_QUERY), $query);

        $this->assertSame('login', $query['prompt']);
    }

    public function test_redirect_uri_can_be_overridden(): void
    {
        config(['sso.redirect_uri' => 'https://app.example/sso/callback']);

        $this->assertSame('https://app.example/sso/callback', $this->sso()->redirectUri());
    }

    public function test_enabled_requires_the_flag_and_full_configuration(): void
    {
        $this->assertTrue($this->sso()->enabled());

        config(['sso.enabled' => false]);
        $this->assertFalse($this->sso()->enabled());

        config(['sso.enabled' => true, 'sso.client_secret' => '']);
        $this->assertFalse($this->sso()->enabled());
    }

    public function test_exchange_posts_the_code_as_a_form_and_maps_the_result(): void
    {
        SsoResponses::fake(['email' => '  ', 'title' => null], ['auth_time' => 1765000000, 'amr' => ['pwd', 'otp']]);

        $result = $this->sso()->exchange('the-code');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://sso.test/api/sso/token'
                && $request->method() === 'POST'
                && $request->isForm()
                && $request['client_id'] === 'demo'
                && $request['client_secret'] === 'demo-secret'
                && $request['code'] === 'the-code';
        });

        $this->assertSame('8f14e45f-ceea-467a-9f3b-1c2d3e4f5a6b', $result->user->guid);
        $this->assertSame('jdelacruz', $result->user->username);
        $this->assertSame('012345', $result->user->employeeNumber);
        $this->assertNull($result->user->email, 'blank values become null');
        $this->assertNull($result->user->title);
        $this->assertSame(1765000000, $result->authTime);
        $this->assertSame(['pwd', 'otp'], $result->amr);
        $this->assertTrue($result->usedTwoFactor());
    }

    public function test_exchange_maps_error_statuses_to_reasons(): void
    {
        $cases = [
            [401, SsoException::INVALID_CLIENT],
            [400, SsoException::INVALID_GRANT],
            [422, SsoException::INVALID_RESPONSE],
            [429, SsoException::INVALID_RESPONSE],
            [500, SsoException::INVALID_RESPONSE],
            [503, SsoException::UNAVAILABLE],
        ];

        foreach ($cases as [$status, $reason]) {
            SsoResponses::fakeError('whatever', $status);

            $this->assertSame($reason, $this->reasonFor('code'), "HTTP {$status}");
        }
    }

    public function test_exchange_reports_an_unreachable_sso(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 60: SSL certificate problem');
        });

        $this->assertSame(SsoException::UNREACHABLE, $this->reasonFor('code'));
    }

    public function test_exchange_rejects_a_response_without_a_user(): void
    {
        Http::fake(['*/api/sso/token' => Http::response(['auth_time' => 1], 200)]);

        $this->assertSame(SsoException::INVALID_RESPONSE, $this->reasonFor('code'));
    }

    public function test_exchange_never_puts_the_secret_in_exception_context(): void
    {
        SsoResponses::fakeError('invalid_client', 401);

        try {
            $this->sso()->exchange('code');
            $this->fail('Expected an SsoException.');
        } catch (SsoException $e) {
            $this->assertStringNotContainsString('demo-secret', json_encode($e->context()).$e->getMessage());
        }
    }

    public function test_verify_accepts_booleans_strings_and_ca_paths(): void
    {
        $sso = new class(app('config')) extends SsoClient {
            public function verifyValue()
            {
                return $this->verify();
            }
        };

        foreach ([[true, true], [false, false], ['false', false], ['0', false], ['1', true], [null, true], ['/etc/ssl/ca.pem', '/etc/ssl/ca.pem']] as [$in, $out]) {
            config(['sso.verify' => $in]);
            $this->assertSame($out, $sso->verifyValue(), var_export($in, true));
        }
    }

    protected function reasonFor(string $code): ?string
    {
        try {
            $this->sso()->exchange($code);
        } catch (SsoException $e) {
            return $e->reason();
        }

        return null;
    }
}
