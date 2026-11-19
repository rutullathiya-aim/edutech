<?php

namespace App\Concerns;

use App\Enums\IndianState;
use App\Enums\SchoolBoard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait SchoolValidationRules
{
    /**
     * Get the validation rules used to validate school details.
     *
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    protected function schoolRules(): array
    {
        return [
            'name' => $this->schoolNameRules(),
            'email' => $this->schoolEmailRules(),
            'phone' => $this->schoolPhoneRules(),
            'board' => $this->schoolBoardRules(),
            'affiliation_number' => $this->schoolAffiliationNumberRules(),
            'udise_code' => $this->schoolUdiseCodeRules(),
            'address_line_1' => $this->schoolAddressLine1Rules(),
            'address_line_2' => $this->schoolAddressLine2Rules(),
            'city' => $this->schoolCityRules(),
            'state' => $this->schoolStateRules(),
            'pincode' => $this->schoolPincodeRules(),
        ];
    }

    /**
     * Get validation rules for school name.
     *
     * @return array<int, string>
     */
    protected function schoolNameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get validation rules for school office email.
     *
     * @return array<int, string>
     */
    protected function schoolEmailRules(): array
    {
        return ['nullable', 'string', 'email', 'max:255'];
    }

    /**
     * Get validation rules for school phone (allows landlines and mobiles: 10-13 digits).
     *
     * @return array<int, string>
     */
    protected function schoolPhoneRules(): array
    {
        return ['nullable', 'string', 'regex:/^(?=(?:\D*\d){10,13}\D*$)[0-9\s\+\-]+$/'];
    }

    /**
     * Get validation rules for education board.
     *
     * @return array<int, Enum|string>
     */
    protected function schoolBoardRules(): array
    {
        return ['nullable', Rule::enum(SchoolBoard::class)];
    }

    /**
     * Get validation rules for affiliation number.
     *
     * @return array<int, string>
     */
    protected function schoolAffiliationNumberRules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Get validation rules for UDISE code (must be exactly 11 digits).
     *
     * @return array<int, string>
     */
    protected function schoolUdiseCodeRules(): array
    {
        return ['nullable', 'string', 'regex:/^[0-9]{11}$/'];
    }

    /**
     * Get validation rules for address line 1.
     *
     * @return array<int, string>
     */
    protected function schoolAddressLine1Rules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Get validation rules for address line 2.
     *
     * @return array<int, string>
     */
    protected function schoolAddressLine2Rules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Get validation rules for city.
     *
     * @return array<int, string>
     */
    protected function schoolCityRules(): array
    {
        return ['nullable', 'string', 'max:255'];
    }

    /**
     * Get validation rules for Indian state.
     *
     * @return array<int, Enum|string>
     */
    protected function schoolStateRules(): array
    {
        return ['nullable', Rule::enum(IndianState::class)];
    }

    /**
     * Get validation rules for Indian PIN code (exactly 6 digits, first digit 1-9).
     *
     * @return array<int, string>
     */
    protected function schoolPincodeRules(): array
    {
        return ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/'];
    }
}
