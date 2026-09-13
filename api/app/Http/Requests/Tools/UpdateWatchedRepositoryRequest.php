<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWatchedRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'muted' => ['sometimes', 'boolean'],
            'watch_priority' => ['sometimes', 'string', Rule::in(['high', 'normal', 'low'])],
        ];
    }

    public function messages(): array
    {
        return [
            'watch_priority.in' => 'watch_priority 必须是 high、normal 或 low',
        ];
    }
}
