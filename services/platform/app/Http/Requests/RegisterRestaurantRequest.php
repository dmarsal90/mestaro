<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterRestaurantRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{2,29}$/'],
        ];
    }
}
