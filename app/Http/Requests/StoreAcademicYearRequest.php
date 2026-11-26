<?php

namespace App\Http\Requests;

use App\Concerns\AcademicYearValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class StoreAcademicYearRequest extends FormRequest
{
    use AcademicYearValidationRules;

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
     * @return array<string, array<int, Unique|ValidationRule|string>>
     */
    public function rules(): array
    {
        return $this->academicYearRules();
    }
}
