<x-layouts::auth.card :title="__('Invitation unavailable')">
    <div class="flex flex-col gap-6 text-center" data-test="invalid-invitation">
        <x-auth-header
            :title="__('Invitation unavailable')"
            :description="__('This invitation is no longer valid. Ask your school to send a new one.')"
        />

        <div class="flex justify-center">
            <flux:button variant="outline" :href="route('home')" wire:navigate>
                {{ __('Return home') }}
            </flux:button>
        </div>
    </div>
</x-layouts::auth.card>
