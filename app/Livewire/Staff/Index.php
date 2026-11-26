<?php

namespace App\Livewire\Staff;

use App\Actions\Staff\ChangeStaffRole;
use App\Actions\Staff\DisableMembership;
use App\Actions\Staff\EnableMembership;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResendInvitation;
use App\Actions\Staff\RevokeInvitation;
use App\Enums\MembershipStatus;
use App\Enums\SchoolRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Tenant\CurrentSchool;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as EmptyPaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read School $school
 * @property-read bool $canManage
 * @property-read list<SchoolRole> $assignableRoles
 * @property-read LengthAwarePaginator<int, Membership> $memberships
 * @property-read LengthAwarePaginator<int, Invitation> $invitations
 */
#[Title('Staff')]
class Index extends Component
{
    use WithPagination;

    public string $activeTab = 'active';

    public string $search = '';

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public bool $showInviteModal = false;

    #[Locked]
    public ?string $editingMembershipId = null;

    public string $editingMemberName = '';

    public string $selectedRole = '';

    public bool $showRoleModal = false;

    /**
     * Authorize access to the staff directory page.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Membership::class);
    }

    /**
     * Switch between Active, Invitations, and Disabled tabs.
     */
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['active', 'invitations', 'disabled'], true) ? $tab : 'active';
        $this->resetPage();
    }

    /**
     * Reset pagination whenever the search filter updates.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Get the current school from tenant context.
     */
    #[Computed]
    public function school(): School
    {
        return CurrentSchool::getOrFail();
    }

    /**
     * Determine whether the current user can invite and manage staff.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('create', Membership::class) ?? false;
    }

    /**
     * Get the roles the current actor can invite or assign.
     *
     * @return list<SchoolRole>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        $actorStaffRole = $actor->staffRoleForSchool($this->school->id);

        return $actorStaffRole !== null ? SchoolRole::assignableBy($actorStaffRole) : [];
    }

    /**
     * Load paginated active or disabled staff memberships for the current school with roles eager-loaded.
     *
     * @return LengthAwarePaginator<int, Membership>
     */
    #[Computed]
    public function memberships(): LengthAwarePaginator
    {
        if (! in_array($this->activeTab, ['active', 'disabled'], true)) {
            return new EmptyPaginator([], 0, 25);
        }

        $schoolId = $this->school->id;
        $status = $this->activeTab === 'disabled'
            ? MembershipStatus::Disabled
            : MembershipStatus::Active;

        $like = $this->buildEscapedLikePattern();

        return Membership::query()
            ->where('school_id', $schoolId)
            ->where('status', $status)
            ->withSchoolRole(SchoolRole::staffCases(), $schoolId)
            ->when($like !== null, function (Builder $query) use ($like): void {
                $query->whereHas('user', function (Builder $userQuery) use ($like): void {
                    $this->applyNameOrEmailSearch($userQuery, $like);
                });
            })
            ->with(['user.roles'])
            ->orderBy('joined_at')
            ->orderBy('id')
            ->paginate(25);
    }

    /**
     * Load paginated open invitations (pending and expired) for the current school.
     *
     * @return LengthAwarePaginator<int, Invitation>
     */
    #[Computed]
    public function invitations(): LengthAwarePaginator
    {
        if ($this->activeTab !== 'invitations') {
            return new EmptyPaginator([], 0, 25);
        }

        $like = $this->buildEscapedLikePattern();

        return Invitation::query()
            ->open()
            ->with('inviter')
            ->when($like !== null, function (Builder $query) use ($like): void {
                $this->applyNameOrEmailSearch($query, $like);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Open the modal to invite a new staff member.
     */
    public function openInviteModal(): void
    {
        $this->authorize('create', Membership::class);

        $this->resetInviteForm();
        $defaultRole = $this->assignableRoles[0] ?? null;
        $this->role = $defaultRole instanceof SchoolRole ? $defaultRole->value : '';
        $this->showInviteModal = true;
    }

    /**
     * Validate and send a staff invitation through the InviteStaff action.
     */
    public function sendInvite(InviteStaff $inviteStaff): void
    {
        $this->authorize('create', Membership::class);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $inviteStaff->handle($actor, $this->name, $this->email, $this->role);

        $this->resetInviteForm();
        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Staff invitation sent.'));
    }

    /**
     * Open the modal to change the target staff member's role.
     */
    public function openChangeRoleModal(string $membershipId): void
    {
        $membership = $this->findSchoolMembership($membershipId);
        $this->authorize('update', $membership);

        $this->resetRoleForm();
        $this->editingMembershipId = $membership->id;
        $this->editingMemberName = $membership->user->name;
        $staffRole = $membership->staffRole();
        $this->selectedRole = $staffRole !== null ? $staffRole->value : '';
        $this->showRoleModal = true;
    }

    /**
     * Persist a staff role change using ChangeStaffRole.
     */
    public function saveRoleChange(ChangeStaffRole $changeStaffRole): void
    {
        if ($this->editingMembershipId === null) {
            return;
        }

        $membership = $this->findSchoolMembership($this->editingMembershipId);
        $this->authorize('update', $membership);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $changeStaffRole->handle($actor, $membership, $this->selectedRole);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['role'])) {
                throw ValidationException::withMessages([
                    'selectedRole' => $errors['role'],
                ]);
            }

            throw $e;
        }

        $this->resetRoleForm();
        unset($this->memberships);
        Flux::toast(variant: 'success', text: __('Staff role updated.'));
    }

    /**
     * Disable an active staff member within the current school.
     */
    public function disableMember(string $membershipId, DisableMembership $disableMembership): void
    {
        $membership = $this->findSchoolMembership($membershipId);
        $this->authorize('update', $membership);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $disableMembership->handle($actor, $membership);

        unset($this->memberships);
        Flux::toast(variant: 'success', text: __('Staff member disabled.'));
    }

    /**
     * Re-enable a disabled staff member within the current school.
     */
    public function enableMember(string $membershipId, EnableMembership $enableMembership): void
    {
        $membership = $this->findSchoolMembership($membershipId);
        $this->authorize('update', $membership);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $enableMembership->handle($actor, $membership);

        unset($this->memberships);
        Flux::toast(variant: 'success', text: __('Staff member re-enabled.'));
    }

    /**
     * Resend an open invitation with token rotation and cooldown enforcement.
     */
    public function resendInvitation(string $invitationId, ResendInvitation $resendInvitation): void
    {
        $invitation = Invitation::query()->findOrFail($invitationId);
        $this->authorize('resend', $invitation);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $resendInvitation->handle($actor, $invitation);
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();
            Flux::toast(variant: 'danger', text: $message !== '' ? $message : __('Unable to resend invitation.'));

            throw $e;
        }

        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation resent.'));
    }

    /**
     * Revoke an open invitation in the current school.
     */
    public function revokeInvitation(string $invitationId, RevokeInvitation $revokeInvitation): void
    {
        $invitation = Invitation::query()->findOrFail($invitationId);
        $this->authorize('revoke', $invitation);

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $revokeInvitation->handle($actor, $invitation);

        unset($this->invitations);
        Flux::toast(variant: 'success', text: __('Invitation revoked.'));
    }

    /**
     * Reset invite modal form state and validation errors.
     */
    public function resetInviteForm(): void
    {
        $this->reset(['name', 'email', 'role', 'showInviteModal']);
        $this->resetValidation();
    }

    /**
     * Reset role-change modal state and validation errors.
     */
    public function resetRoleForm(): void
    {
        $this->reset(['editingMembershipId', 'editingMemberName', 'selectedRole', 'showRoleModal']);
        $this->resetValidation();
    }

    /**
     * Resolve a membership strictly scoped to the current school or fail with 404.
     */
    private function findSchoolMembership(string $membershipId): Membership
    {
        return Membership::query()
            ->where('school_id', $this->school->id)
            ->with(['user.roles'])
            ->findOrFail($membershipId);
    }

    /**
     * Build a database-portable LIKE pattern escaping wildcard characters with '!'.
     */
    private function buildEscapedLikePattern(): ?string
    {
        $search = trim($this->search);

        if ($search === '') {
            return null;
        }

        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }

    /**
     * Filter a query by matching name or email against an escaped LIKE pattern.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyNameOrEmailSearch(Builder $query, string $like): void
    {
        $query->where(static function (Builder $nameOrEmail) use ($like): void {
            $nameOrEmail->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$like]);
        });
    }

    /**
     * Render the staff directory component view.
     */
    public function render(): View
    {
        return view('livewire.staff.index');
    }
}
