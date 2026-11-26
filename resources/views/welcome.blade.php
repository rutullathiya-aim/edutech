<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('School Management Platform')])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="mx-auto flex min-h-screen max-w-5xl flex-col justify-between px-6 py-8">
            <header class="flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-semibold tracking-tight">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                        <x-app-logo-icon class="size-5 fill-current" />
                    </span>
                    <span class="text-lg">{{ config('app.name', 'Edutech') }}</span>
                </a>

                @if (Route::has('login'))
                    <nav class="flex items-center gap-3 text-sm">
                        @auth
                            <a
                                href="{{ $dashboardUrl }}"
                                class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                            >
                                {{ __('Dashboard') }}
                            </a>
                        @else
                            <a
                                href="{{ route('login') }}"
                                class="inline-flex items-center rounded-lg px-4 py-2 font-medium text-zinc-700 transition hover:bg-zinc-200/70 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                {{ __('Log in') }}
                            </a>

                            @if (Route::has('schools.register'))
                                <a
                                    href="{{ route('schools.register') }}"
                                    class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                >
                                    {{ __('Register your school') }}
                                </a>
                            @endif
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="my-12">
                <div class="max-w-2xl space-y-6">
                    <span class="inline-flex items-center rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        {{ __('Built for Indian Schools') }}
                    </span>

                    <h1 class="text-4xl font-semibold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                        {{ __('Run every academic year, class, and curriculum cleanly in :app.', ['app' => config('app.name', 'Edutech')]) }}
                    </h1>

                    <p class="text-base leading-relaxed text-zinc-600 sm:text-lg dark:text-zinc-400">
                        {{ __('Set up your school in minutes — academic sessions, grades, sections, and CBSE, CISCE, or State Board subject curricula under strict school isolation.') }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        @auth
                            <a
                                href="{{ $dashboardUrl }}"
                                class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                            >
                                {{ __('Go to Dashboard') }}
                            </a>
                        @else
                            @if (Route::has('schools.register'))
                                <a
                                    href="{{ route('schools.register') }}"
                                    class="inline-flex items-center rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                                >
                                    {{ __('Register your school') }}
                                </a>
                            @endif

                            <a
                                href="{{ route('login') }}"
                                class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-5 py-2.5 text-sm font-medium text-zinc-800 transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                {{ __('Log in') }}
                            </a>
                        @endauth
                    </div>
                </div>
            </main>

            <footer class="border-t border-zinc-200 pt-6 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                &copy; {{ date('Y') }} {{ config('app.name', 'Edutech') }}. {{ __('Multi-tenant school platform.') }}
            </footer>
        </div>
    </body>
</html>
