@props([
    'schools',
])

<flux:dropdown position="bottom" align="start" class="w-full" data-test="school-switcher">
    <flux:sidebar.brand
        as="button"
        :name="\App\Tenant\CurrentSchool::get()?->name ?? __('Switch school')"
        icon:trailing="chevrons-up-down"
        class="w-full border border-zinc-200 dark:border-zinc-700"
        data-test="school-switcher-button"
    >
        <x-slot name="logo" class="flex aspect-square size-6 items-center justify-center rounded-sm bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
            <flux:icon.building-office-2 class="size-4" />
        </x-slot>
    </flux:sidebar.brand>

    <flux:menu class="w-60" data-test="school-switcher-menu">
        <flux:menu.heading>{{ __('Schools') }}</flux:menu.heading>

        @foreach ($schools as $school)
            <flux:menu.item
                :href="route('dashboard', ['school' => $school->id])"
                :icon="\App\Tenant\CurrentSchool::id() === $school->id ? 'check' : null"
            >
                {{ $school->name }}
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
