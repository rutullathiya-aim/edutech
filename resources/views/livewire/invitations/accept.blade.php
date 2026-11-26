<div class="flex flex-col gap-6">
    @if ($this->state === 'invalid')
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
    @elseif ($this->state === 'create_account' && $this->invitation !== null)
        <x-auth-header
            :title="__('Join :school as :role', ['school' => $this->invitation->school?->name, 'role' => $this->invitation->role->value])"
            :description="__('Create your account to join the school team.')"
        />

        @error('invitation')
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
        @enderror

        <form wire:submit="registerAndAccept" class="flex flex-col gap-5" data-test="accept-create-account-form">
            <flux:field>
                <flux:label>{{ __('Email address') }}</flux:label>
                <flux:input
                    type="text"
                    :value="$this->invitation->maskedEmail()"
                    disabled
                    readonly
                    data-test="masked-invitation-email"
                />
            </flux:field>

            <flux:input
                wire:model="name"
                :label="__('Full name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Your full name')"
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

            <flux:checkbox
                wire:model="terms"
                :label="__('I accept the Terms of Service and Privacy Policy')"
            />

            <flux:button type="submit" variant="primary" class="w-full" data-test="accept-create-account-button">
                {{ __('Create account and join') }}
            </flux:button>
        </form>
    @elseif ($this->state === 'must_login' && $this->invitation !== null)
        <div class="flex flex-col gap-6" data-test="accept-must-login">
            <x-auth-header
                :title="__('Join :school as :role', ['school' => $this->invitation->school?->name, 'role' => $this->invitation->role->value])"
                :description="__('You already have an account. Log in to accept.')"
            />

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" data-test="masked-invitation-email">
                {{ __('Invited email: :email', ['email' => $this->invitation->maskedEmail()]) }}
            </div>

            <flux:button variant="primary" class="w-full" :href="route('login')" data-test="invitation-login-link">
                {{ __('Log in to accept') }}
            </flux:button>
        </div>
    @elseif ($this->state === 'existing_logged_in' && $this->invitation !== null)
        <div class="flex flex-col gap-6" data-test="accept-existing-user">
            <x-auth-header
                :title="__('Join :school as :role', ['school' => $this->invitation->school?->name, 'role' => $this->invitation->role->value])"
                :description="__('Confirm below to accept this staff invitation.')"
            />

            @error('invitation')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" data-test="masked-invitation-email">
                {{ __('Signed in as :email', ['email' => $this->invitation->maskedEmail()]) }}
            </div>

            <flux:button
                type="button"
                variant="primary"
                class="w-full"
                wire:click="acceptExisting"
                data-test="accept-invitation-button"
            >
                {{ __('Accept invitation') }}
            </flux:button>
        </div>
    @elseif ($this->state === 'wrong_user' && $this->invitation !== null)
        <div class="flex flex-col gap-6" data-test="accept-wrong-user">
            <x-auth-header
                :title="__('Wrong account signed in')"
                :description="__('This invitation was sent to :email. Log out and sign in with that account.', ['email' => $this->invitation->maskedEmail()])"
            />

            @error('invitation')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full" data-test="invitation-logout-button">
                    {{ __('Log out') }}
                </flux:button>
            </form>
        </div>
    @endif
</div>
