<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'contribution_amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'frequency' => ['required', Rule::in(['weekly', 'monthly'])],
            'total_payments' => ['required_if:payout_type,lump_sum', 'nullable', 'integer', 'min:1', 'max:500'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'payout_type' => ['required', Rule::in(['lump_sum', 'rotating'])],
        ];
    }
}
