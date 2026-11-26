<div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 py-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-1">
            <flux:heading size="xl">{{ __('Staff') }}</flux:heading>
            <flux:subheading>{{ __('Manage school leaders, accountants, teachers, and pending invitations.') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <flux:button
                variant="primary"
                icon="plus"
                wire:click="openInviteModal"
                data-test="invite-staff-button"
            >
                {{ __('Invite staff') }}
            </flux:button>
        @endif
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                :variant="$activeTab === 'active' ? 'primary' : 'outline'"
                wire:click="setTab('active')"
                data-test="tab-active"
            >
                {{ __('Active') }}
            </flux:button>

            <flux:button
                :variant="$activeTab === 'invitations' ? 'primary' : 'outline'"
                wire:click="setTab('invitations')"
                data-test="tab-invitations"
            >
                {{ __('Invitations') }}
            </flux:button>

            <flux:button
                :variant="$activeTab === 'disabled' ? 'primary' : 'outline'"
                wire:click="setTab('disabled')"
                data-test="tab-disabled"
            >
                {{ __('Disabled') }}
            </flux:button>
        </div>

        <div class="w-full sm:w-72">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search name or email...')"
                clearable
                data-test="staff-search"
            />
        </div>
    </div>

    <flux:card class="space-y-4 p-6">
        @if ($activeTab === 'invitations')
            @if ($this->invitations->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>{{ __('No open invitations') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Invited staff who have not yet accepted will appear here.') }}
                    </flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                        <flux:table.column>{{ __('Role') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Sent') }}</flux:table.column>
                        <flux:table.column>{{ __('Expires') }}</flux:table.column>
                        @if ($this->canManage)
                            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->invitations as $invitation)
                            <flux:table.row :key="$invitation->id" data-test="invitation-row-{{ $invitation->id }}">
                                <flux:table.cell variant="strong">{{ $invitation->name }}</flux:table.cell>
                                <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge color="sky" size="sm">{{ $invitation->role->value }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($invitation->isExpired())
                                        <flux:badge color="amber" size="sm">{{ __('Expired') }}</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm">{{ __('Pending') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ ($invitation->last_sent_at ?? $invitation->created_at)?->timezone($this->school->timezone ?: 'Asia/Kolkata')->format('d M Y') }}</flux:table.cell>
                                <flux:table.cell>{{ $invitation->expires_at?->timezone($this->school->timezone ?: 'Asia/Kolkata')->format('d M Y') ?? '—' }}</flux:table.cell>

                                @if ($this->canManage)
                                    <flux:table.cell align="end">
                                        <div class="flex items-center justify-end gap-2">
                                            @can('resend', $invitation)
                                                <flux:button
                                                    size="sm"
                                                    variant="outline"
                                                    wire:click="resendInvitation('{{ $invitation->id }}')"
                                                    data-test="resend-invitation-{{ $invitation->id }}"
                                                >
                                                    {{ __('Resend') }}
                                                </flux:button>
                                            @endcan

                                            @can('revoke', $invitation)
                                                <flux:button
                                                    size="sm"
                                                    variant="danger"
                                                    wire:click="revokeInvitation('{{ $invitation->id }}')"
                                                    wire:confirm="{{ __('Revoke the invitation for :name? They will no longer be able to accept it.', ['name' => $invitation->name]) }}"
                                                    data-test="revoke-invitation-{{ $invitation->id }}"
                                                >
                                                    {{ __('Revoke') }}
                                                </flux:button>
                                            @endcan
                                        </div>
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                {{ $this->invitations->links() }}
            @endif
        @else
            @if ($this->memberships->isEmpty())
                <div class="py-10 text-center">
                    <flux:heading>
                        {{ $activeTab === 'disabled' ? __('No disabled staff members') : __('No active staff members found') }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        {{ $activeTab === 'disabled'
                            ? __('Disabled staff accounts will appear here so they can be re-enabled anytime.')
                            : __('Invite school administrators, accountants, or teachers to get started.') }}
                    </flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Email') }}</flux:table.column>
                        <flux:table.column>{{ __('Role') }}</flux:table.column>
                        <flux:table.column>{{ __('Joined') }}</flux:table.column>
                        @if ($this->canManage)
                            <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->memberships as $membership)
                            @php
                                $staffRole = $membership->staffRole();
                            @endphp
                            <flux:table.row :key="$membership->id" data-test="staff-row-{{ $membership->id }}">
                                <flux:table.cell variant="strong">{{ $membership->user->name }}</flux:table.cell>
                                <flux:table.cell>{{ $membership->user->email }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($staffRole !== null)
                                        <flux:badge
                                            :color="$staffRole === \App\Enums\SchoolRole::Owner ? 'purple' : ($staffRole === \App\Enums\SchoolRole::Admin ? 'sky' : 'zinc')"
                                            size="sm"
                                        >
                                            {{ $staffRole->value }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ $membership->joined_at->timezone($this->school->timezone ?: 'Asia/Kolkata')->format('d M Y') }}</flux:table.cell>

                                @if ($this->canManage)
                                    <flux:table.cell align="end">
                                        @can('update', $membership)
                                            <div class="flex items-center justify-end gap-2">
                                                @if ($activeTab === 'active')
                                                    <flux:button
                                                        size="sm"
                                                        variant="outline"
                                                        wire:click="openChangeRoleModal('{{ $membership->id }}')"
                                                        data-test="change-role-{{ $membership->id }}"
                                                    >
                                                        {{ __('Change role') }}
                                                    </flux:button>

                                                    <flux:button
                                                        size="sm"
                                                        variant="danger"
                                                        wire:click="disableMember('{{ $membership->id }}')"
                                                        wire:confirm="{{ __('Disable :name? They will lose access immediately.', ['name' => $membership->user->name]) }}"
                                                        data-test="disable-member-{{ $membership->id }}"
                                                    >
                                                        {{ __('Disable') }}
                                                    </flux:button>
                                                @else
                                                    <flux:button
                                                        size="sm"
                                                        variant="outline"
                                                        wire:click="enableMember('{{ $membership->id }}')"
                                                        data-test="enable-member-{{ $membership->id }}"
                                                    >
                                                        {{ __('Re-enable') }}
                                                    </flux:button>
                                                @endif
                                            </div>
                                        @endcan
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                {{ $this->memberships->links() }}
            @endif
        @endif
    </flux:card>

    <flux:modal
        name="invite-staff-modal"
        wire:model="showInviteModal"
        @close="resetInviteForm"
        class="max-w-lg"
    >
        <form wire:submit="sendInvite" class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Invite staff') }}</flux:heading>
                <flux:subheading>{{ __('Send an invitation email to add a team member to this school.') }}</flux:subheading>
            </div>

            <flux:input
                wire:model="name"
                :label="__('Full name')"
                type="text"
                required
                :placeholder="__('e.g. Meera Joshi')"
                data-test="invite-name-input"
            />

            <flux:input
                wire:model="email"
                :label="__('Email address')"
                type="email"
                required
                :placeholder="__('e.g. meera@school.in')"
                data-test="invite-email-input"
            />

            <flux:select wire:model="role" :label="__('Role')" required data-test="invite-role-select">
                @foreach ($this->assignableRoles as $assignableRole)
                    <flux:select.option :value="$assignableRole->value">{{ $assignableRole->value }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button type="button" variant="ghost" wire:click="resetInviteForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" data-test="send-invite-button">
                    {{ __('Send invitation') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        name="change-role-modal"
        wire:model="showRoleModal"
        @close="resetRoleForm"
        class="max-w-md"
    >
        <form wire:submit="saveRoleChange" class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Change role') }}</flux:heading>
                <flux:subheading>{{ __('Update staff role for :name.', ['name' => $editingMemberName]) }}</flux:subheading>
            </div>

            <flux:select wire:model="selectedRole" :label="__('Role')" required data-test="change-role-select">
                @foreach ($this->assignableRoles as $assignableRole)
                    <flux:select.option :value="$assignableRole->value">{{ $assignableRole->value }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button type="button" variant="ghost" wire:click="resetRoleForm">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" data-test="save-role-button">
                    {{ __('Save role') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
