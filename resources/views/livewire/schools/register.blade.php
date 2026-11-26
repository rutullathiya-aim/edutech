<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Register your school')"
        :description="__('Start your free :days-day school trial and set up your owner account', ['days' => config('schools.trial_days', 30)])"
    />

    @error('throttle')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
    @enderror

    <form wire:submit="register" class="flex flex-col gap-6">
        <div aria-hidden="true" class="hidden">
            <label for="contact_hp_x9k">{{ __('Contact reference') }}</label>
            <input
                id="contact_hp_x9k"
                type="text"
                wire:model="contact_hp_x9k"
                tabindex="-1"
                autocomplete="new-password"
            />
        </div>

        <div class="flex flex-col gap-4">
            <flux:heading size="sm">{{ __('School details') }}</flux:heading>

            <flux:input
                wire:model="school_name"
                :label="__('School name')"
                type="text"
                required
                autofocus
                :placeholder="__('e.g. Sunrise Public School')"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="city"
                    :label="__('City')"
                    type="text"
                    required
                    :placeholder="__('e.g. Ahmedabad')"
                />

                <flux:select wire:model="state" :label="__('State')" :placeholder="__('Select state')" required>
                    @foreach ($this->states as $indianState)
                        <flux:select.option :value="$indianState->value">{{ $indianState->value }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model="board" :label="__('Education board (optional)')">
                <flux:select.option value="">{{ __('Select later') }}</flux:select.option>
                @foreach ($this->boards as $schoolBoard)
                    <flux:select.option :value="$schoolBoard->value">{{ $schoolBoard->value }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:separator />

        <div class="flex flex-col gap-4">
            <flux:heading size="sm">{{ __('Owner account') }}</flux:heading>

            <flux:input
                wire:model="name"
                :label="__('Full name')"
                type="text"
                required
                autocomplete="name"
                :placeholder="__('Your full name')"
            />

            <flux:input
                wire:model="email"
                :label="__('Email address')"
                type="email"
                required
                autocomplete="email"
                placeholder="owner@school.org"
            />

            <flux:input
                wire:model="mobile"
                :label="__('Mobile number')"
                type="tel"
                required
                autocomplete="tel"
                placeholder="+919876543210"
            />

            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />
        </div>

        <flux:checkbox
            wire:model="terms"
            :label="__('I accept the Terms of Service and Privacy Policy')"
        />

        <flux:button type="submit" variant="primary" class="w-full" data-test="register-school-button">
            {{ __('Register school') }}
        </flux:button>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
        <span>{{ __('Already have an account?') }}</span>
        <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
    </div>
</div>
