<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $time = ['required', 'date_format:H:i'];

        return [
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/'],
            'opening_hours' => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'opening_hours.*' => ['array', 'max:4'],
            'opening_hours.*.*' => ['array:open,close'],
            'opening_hours.*.*.open' => $time,
            'opening_hours.*.*.close' => $time,
            'auto_accept_orders' => ['sometimes', 'boolean'],
            'require_waiter_approval_for_session' => ['sometimes', 'boolean'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'default_tax_bps' => ['sometimes', 'integer', 'between:0,10000'],
            'join_code_reuse_cooldown_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'order_alert_threshold_seconds' => ['sometimes', 'integer', 'between:30,86400'],
            'preferences' => ['sometimes', 'nullable', 'array', function ($attribute, $value, $fail) {
                if (strlen(json_encode($value)) > 8192) {
                    $fail('The preferences are too large.');
                }
            }],
        ];
    }
}
