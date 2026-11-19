<?php

namespace App\Http\Requests;

use App\Concerns\SchoolValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SchoolProfileRequest extends FormRequest
{
    use SchoolValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    public function rules(): array
    {
        return $this->schoolRules();
    }
}
