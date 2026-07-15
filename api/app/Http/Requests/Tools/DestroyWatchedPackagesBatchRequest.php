<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

class DestroyWatchedPackagesBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'ID 列表不能为空',
            'ids.min' => '至少需要一个 ID',
            'ids.*.integer' => 'ID 必须为整数',
        ];
    }
}
