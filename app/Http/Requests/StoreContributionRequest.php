<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContributionRequest extends FormRequest
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
        $groups = $this->route('group');
        return [
            'group_member_id' => ['required',
             'integer', 
            Rule::exists('group_members', 'id')->where('group_id', $groups->id)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'period_number' => ['required', 'integer', 'min:1', 'max:' .$groups->currentPeriod()],
            'payment_method' => ['required', Rule::in(['wave', 'aps', 'cash'])],
        ];
    }

    public function messages(): array
{
    return [
        'period_number.max' => 'That period has not started yet.',
    ];
}

   
}
