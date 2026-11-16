<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StaffRequest extends FormRequest
{
    public const ROLES = ['restaurant_admin', 'manager', 'waiter', 'kitchen', 'bar'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('POST')) {
            return [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:254'],
                'role' => ['required', Rule::in(self::ROLES)],
            ];
        }

        return [
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
