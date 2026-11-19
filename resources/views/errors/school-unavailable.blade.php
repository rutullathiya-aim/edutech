<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('School Unavailable')])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 flex items-center justify-center p-6">
        <div class="max-w-md w-full text-center space-y-4">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-600 dark:text-amber-400 mb-2">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight">
                {{ __('School Unavailable') }}
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('The school ":name" is currently unavailable. This may be due to an expired trial, suspension, or cancellation.', ['name' => $school->name]) }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-500">
                {{ __('Please contact your school administrator or platform support for assistance.') }}
            </p>
        </div>
    </body>
</html>
