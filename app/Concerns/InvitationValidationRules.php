<?php

namespace App\Concerns;

use App\Enums\SchoolRole;
use App\Support\MobileNumber;
use Illuminate\Validation\Rule;

trait InvitationValidationRules
{
    /**
     * Get the generic invitation model validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function invitationRules(): array
    {
        return [
            'email' => [
                'required_without:mobile',
                'nullable',
                'string',
                'email',
                'max:255',
            ],
            'mobile' => [
                'required_without:email',
                'nullable',
                'string',
                'regex:'.MobileNumber::REGEX,
            ],
            'role' => [
                'required',
                Rule::enum(SchoolRole::class)->except(SchoolRole::Owner),
            ],
        ];
    }

    /**
     * Get the validation rules for inviting a staff member by email within an actor's allowed roles.
     *
     * @param  list<SchoolRole>  $assignableRoles
     * @return array<string, array<int, mixed>>
     */
    protected function staffInvitationRules(array $assignableRoles): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => [
                'required',
                Rule::enum(SchoolRole::class)->only($assignableRoles),
            ],
        ];
    }
}
