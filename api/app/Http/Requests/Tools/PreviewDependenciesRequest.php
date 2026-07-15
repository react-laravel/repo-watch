<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

class PreviewDependenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => '仓库 URL 不能为空',
            'url.url' => '仓库 URL 格式不正确',
        ];
    }
}
