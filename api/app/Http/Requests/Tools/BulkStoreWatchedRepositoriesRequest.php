<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreWatchedRepositoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $repositories = $this->input('repositories');

        if (is_string($repositories)) {
            $lines = preg_split('/\r\n|\r|\n/', $repositories) ?: [];
            $this->merge([
                'repositories' => array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== '')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'repositories' => ['required', 'array', 'min:1', 'max:50'],
            'repositories.*' => ['required', 'string', 'max:500'],
            'scan' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'repositories.required' => '请提供至少一个仓库',
            'repositories.min' => '请提供至少一个仓库',
            'repositories.max' => '单次最多导入 50 个仓库',
            'repositories.*.required' => '仓库引用不能为空',
        ];
    }
}
