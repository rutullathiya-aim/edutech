<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    @if ($this->schools->isEmpty())
        <div class="mx-auto flex w-full max-w-md flex-1 items-center justify-center py-12">
            <flux:card class="w-full p-8 text-center">
                <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    <flux:icon.building-office-2 class="size-6" />
                </div>

                <flux:heading size="lg">{{ __("You're not part of any school yet") }}</flux:heading>

                <flux:text class="mt-2">
                    {{ __('Once a school administrator invites you and activates your membership, your school will appear here.') }}
                </flux:text>

                <div class="mt-6 flex justify-center">
                    <flux:button :href="route('profile.edit')" variant="ghost" wire:navigate>
                        {{ __('Account settings') }}
                    </flux:button>
                </div>
            </flux:card>
        </div>
    @else
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Select a school') }}</flux:heading>
            <flux:subheading>{{ __('Choose a school to open its dashboard.') }}</flux:subheading>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->schools as $school)
                <a
                    wire:key="school-{{ $school->id }}"
                    href="{{ route('dashboard', ['school' => $school->id]) }}"
                    class="group block focus:outline-hidden"
                >
                    <flux:card class="flex h-full items-center justify-between gap-4 p-5 transition hover:border-zinc-300 dark:hover:border-white/25">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                <flux:icon.academic-cap class="size-5" />
                            </div>

                            <div class="min-w-0">
                                <flux:heading class="truncate">{{ $school->name }}</flux:heading>

                                @php
                                    $location = collect([$school->city, $school->state?->value])
                                        ->filter()
                                        ->implode(', ');
                                @endphp

                                @if ($location !== '')
                                    <flux:text class="mt-0.5 truncate text-xs">{{ $location }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <flux:icon.arrow-right class="size-4 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-zinc-700 dark:group-hover:text-zinc-200" />
                    </flux:card>
                </a>
            @endforeach
        </div>
    @endif
</div>
