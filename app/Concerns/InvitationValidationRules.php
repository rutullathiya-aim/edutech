<?php

namespace App\Concerns;

use App\Enums\SchoolRole;
use App\Support\MobileNumber;
use Illuminate\Validation\Rule;

trait InvitationValidationRules
{
    /**
     * Get the validation rules used to validate invitations.
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
}
