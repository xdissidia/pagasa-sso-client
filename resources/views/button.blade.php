{{--
    "Sign in with PAGASA SSO" button.

        @include('sso::button')                           dark surface
        @include('sso::button', ['light' => true])        light surface
        @include('sso::button', ['divider' => 'or'])      with an "or" rule above
        @include('sso::button', ['label' => 'Continue'])  custom label

    Theme with CSS variables on any ancestor: --pagasa-sso-bg, --pagasa-sso-fg,
    --pagasa-sso-border, --pagasa-sso-bg-hover, --pagasa-sso-border-hover,
    --pagasa-sso-muted, --pagasa-sso-font. Publish with
    `php artisan vendor:publish --tag=sso-views` to restyle it outright.
--}}
@once
<style>
    .pagasa-sso-divider {
        display: flex;
        align-items: center;
        gap: 0.875rem;
        margin: 1.5rem 0;
        font: 600 0.8125rem/1 var(--pagasa-sso-font, inherit);
        color: var(--pagasa-sso-muted, #94a3b8);
        user-select: none;
    }
    .pagasa-sso-divider::before,
    .pagasa-sso-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--pagasa-sso-border, #33415580);
    }
    .pagasa-sso-btn {
        display: flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        box-sizing: border-box;
        padding: 0.75rem 1rem;
        border: 1px solid var(--pagasa-sso-border, #334155);
        border-radius: 0.625rem;
        background: var(--pagasa-sso-bg, #1e293b);
        color: var(--pagasa-sso-fg, #e2e8f0);
        font: 600 0.9375rem/1.25 var(--pagasa-sso-font, inherit);
        text-decoration: none;
        cursor: pointer;
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .pagasa-sso-btn:hover {
        background: var(--pagasa-sso-bg-hover, #26344a);
        border-color: var(--pagasa-sso-border-hover, #475569);
    }
    .pagasa-sso-btn:focus-visible {
        outline: 2px solid #0e5e8d;
        outline-offset: 2px;
    }
    /* White tile: the seal's outer lobes are black on transparency and
       would vanish on a dark button. */
    .pagasa-sso-btn__logo {
        flex: none;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: #fff center / 22px 22px no-repeat;
    }
    .pagasa-sso-btn--light {
        --pagasa-sso-bg: #ffffff;
        --pagasa-sso-bg-hover: #f8fafc;
        --pagasa-sso-border: #d1d5db;
        --pagasa-sso-border-hover: #9ca3af;
        --pagasa-sso-fg: #1b1b18;
    }
</style>
@endonce
@if (! empty($divider))
    <div class="pagasa-sso-divider">{{ $divider }}</div>
@endif
<a class="pagasa-sso-btn{{ ! empty($light) ? ' pagasa-sso-btn--light' : '' }}" href="{{ $href ?? route('sso.login') }}">
    <span class="pagasa-sso-btn__logo" aria-hidden="true" style="background-image: url('{{ rtrim((string) config('sso.url'), '/') }}/images/pagasa-logo-64.png')"></span>
    <span>{{ $label ?? 'Sign in with PAGASA SSO' }}</span>
</a>
