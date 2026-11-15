<x-guest-layout>
    @php
        $logoSettings = \Modules\Core\Entities\Settings::first();
        $generalSettings = app(\Modules\Core\Settings\GeneralSettings::class);
        $siteName = $generalSettings->site_name ?: config('app.name');
        $logoUrl = $logoSettings?->getLogoUrl();
        $currentLocale = app()->getLocale();
        $supportedLocales = \LaravelLocalization::getSupportedLocales();
    @endphp

    <div class="flex min-h-screen">
        {{-- Branding panel --}}
        <div class="relative hidden w-1/2 flex-col overflow-hidden bg-gradient-to-br from-primary to-primary-dark p-10 text-white md:flex">
            <div class="pointer-events-none absolute -end-24 -top-24 h-72 w-72 rounded-full bg-white/10"></div>
            <div class="pointer-events-none absolute -start-16 bottom-10 h-56 w-56 rounded-full bg-white/10"></div>

            <div class="relative flex flex-1 flex-col items-center justify-center">
                <div class="flex h-56 w-56 items-center justify-center rounded-full bg-white shadow-xl">
                    @if ($logoUrl)
                        <img class="h-40 w-40 object-contain" src="{{ $logoUrl }}" alt="{{ $siteName }}">
                    @else
                        <span class="text-6xl font-bold text-primary">
                            {{ mb_substr($siteName, 0, 1) }}
                        </span>
                    @endif
                </div>
                <span class="relative mt-6 text-xl font-semibold">{{ $siteName }}</span>
            </div>

            <div class="relative">
                <h1 class="text-3xl font-bold leading-tight">{{ __('Manage your business, all in one place') }}</h1>
                <p class="mt-3 max-w-sm text-white/80">{{ __('Sign in to access your account and manage your data securely and easily from one place') }}</p>
            </div>
        </div>

        {{-- Form panel --}}
        <div class="relative flex w-full flex-col items-center justify-center px-6 py-12 md:w-1/2">
            <div x-data="{ open: false }" @click.outside="open = false" class="absolute end-6 top-6">
                <button type="button" x-on:click="open = !open" class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                    @svg('heroicon-o-language', 'h-4 w-4')
                    {{ $supportedLocales[$currentLocale]['native'] ?? $currentLocale }}
                    @svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')
                </button>

                <div x-show="open" x-cloak x-transition class="absolute end-0 z-20 mt-2 w-36 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                    @foreach ($supportedLocales as $code => $properties)
                        <a
                            href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                            class="block px-3 py-2 text-sm {{ $currentLocale === $code ? 'bg-primary/10 font-medium text-primary' : 'text-gray-700 hover:bg-gray-50' }}"
                        >
                            {{ $properties['native'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="w-full max-w-sm">
                <div class="mb-8 flex justify-center md:hidden">
                    @if ($logoUrl)
                        <img class="h-14 w-14 object-contain" src="{{ $logoUrl }}" alt="{{ $siteName }}">
                    @else
                        <span class="flex h-14 w-14 items-center justify-center rounded-lg bg-primary text-2xl font-bold text-white">
                            {{ mb_substr($siteName, 0, 1) }}
                        </span>
                    @endif
                </div>

                <h2 class="text-2xl font-bold text-gray-900">{{ __('Sign in to your account') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('Please sign in to continue') }}</p>

                <x-validation-errors class="mt-4" />

                @session('status')
                    <div class="mt-4 text-sm font-medium text-green-600">
                        {{ $value }}
                    </div>
                @endsession

                <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                    @csrf

                    <div>
                        <label for="email" class="mb-1 block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400">
                                @svg('heroicon-o-user', 'h-5 w-5')
                            </span>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                autocomplete="username"
                                class="block w-full rounded-lg border-gray-300 py-2.5 ps-10 text-sm shadow-sm focus:border-primary focus:ring-primary"
                            >
                        </div>
                    </div>

                    <div x-data="{ show: false }">
                        <label for="password" class="mb-1 block text-sm font-medium text-gray-700">{{ __('Password') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400">
                                @svg('heroicon-o-lock-closed', 'h-5 w-5')
                            </span>
                            <input
                                :type="show ? 'text' : 'password'"
                                id="password"
                                name="password"
                                required
                                autocomplete="current-password"
                                class="block w-full rounded-lg border-gray-300 py-2.5 ps-10 pe-10 text-sm shadow-sm focus:border-primary focus:ring-primary"
                            >
                            <button
                                type="button"
                                x-on:click="show = !show"
                                class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                            >
                                <span x-show="!show">@svg('heroicon-o-eye', 'h-5 w-5')</span>
                                <span x-show="show" x-cloak>@svg('heroicon-o-eye-slash', 'h-5 w-5')</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <label for="remember_me" class="flex items-center gap-2 text-sm text-gray-600">
                            <x-checkbox id="remember_me" name="remember" />
                            {{ __('Remember me') }}
                        </label>

                        @if (Route::has('password.request'))
                            <a class="text-sm font-medium text-primary hover:underline" href="{{ route('password.request') }}">
                                {{ __('Forgot Password?') }}
                            </a>
                        @endif
                    </div>

                    <button
                        type="submit"
                        class="w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-white transition hover:bg-primary-dark focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                    >
                        {{ __('Sign In') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-guest-layout>
