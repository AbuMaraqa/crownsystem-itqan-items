<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title')</title>

        {{-- Self-contained on purpose: an error page must render even when the build or the database is down. --}}
        <style>
            :root {
                --ink: #09090b;
                --muted: #52525b;
                --line: #eaeaed;
                --canvas: #ffffff;
                --brand: #1570ef;
                --brand-200: #aacbfb;
                color-scheme: light;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --ink: #fafafa;
                    --muted: #a1a1aa;
                    --line: #232327;
                    --canvas: #09090b;
                    --brand-200: rgb(21 112 239 / 0.35);
                    color-scheme: dark;
                }
            }

            *, ::before, ::after { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px 16px;
                background: var(--canvas);
                color: var(--ink);
                font-family: Tajawal, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            main { display: flex; flex-direction: column; align-items: center; gap: 16px; max-width: 420px; text-align: center; }

            .code {
                font-size: clamp(64px, 18vw, 112px);
                font-weight: 700;
                line-height: 1;
                letter-spacing: -0.04em;
                color: transparent;
                background: linear-gradient(180deg, var(--brand) 0%, var(--brand-200) 100%);
                -webkit-background-clip: text;
                background-clip: text;
                font-variant-numeric: tabular-nums;
            }

            h1 { margin: 0; font-size: 22px; font-weight: 600; line-height: 1.3; }

            .actions { display: flex; gap: 8px; margin-top: 8px; }

            .btn {
                display: inline-flex;
                align-items: center;
                height: 34px;
                padding: 0 12px;
                border-radius: 6px;
                background: var(--brand);
                color: #fff;
                font-size: 13px;
                font-weight: 500;
                text-decoration: none;
                box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            }

            .btn:hover { filter: brightness(0.92); }
            .btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        </style>
    </head>
    <body>
        <main>
            <div class="code" aria-hidden="true">@yield('code')</div>
            <h1>@yield('message')</h1>
            <div class="actions">
                <a href="{{ url('/') }}" class="btn">{{ __('Back to home') }}</a>
            </div>
        </main>
    </body>
</html>
