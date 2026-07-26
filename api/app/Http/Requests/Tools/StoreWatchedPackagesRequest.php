<?php

namespace App\Http\Requests\Tools;

use Illuminate\Foundation\Http\FormRequest;

class StoreWatchedPackagesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $packages = $this->input('packages');

        if (! is_array($packages)) {
            return;
        }

        $this->merge([
            'packages' => array_map(function ($package) {
                if (! is_array($package)) {
                    return $package;
                }

                if (is_string($package['ecosystem'] ?? null)) {
                    $package['ecosystem'] = strtolower(trim($package['ecosystem']));
                }

                if (is_string($package['package_name'] ?? null)) {
                    $package['package_name'] = strtolower(trim($package['package_name']));
                }

                return $package;
            }, $packages),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_url' => ['required', 'url'],
            'source_owner' => ['required', 'string'],
            'source_repo' => ['required', 'string'],
            'packages' => ['required', 'array', 'min:1', 'max:500'],
            'packages.*.ecosystem' => ['required', 'in:npm,composer'],
            'packages.*.package_name' => ['required', 'string', 'max:200'],
            'packages.*.manifest_path' => ['nullable', 'string'],
            'packages.*.current_version_constraint' => ['nullable', 'string'],
            'packages.*.normalized_current_version' => ['nullable', 'string'],
            'packages.*.current_version_source' => ['nullable', 'in:lock,manifest'],
            'packages.*.watch_level' => ['required', 'in:major,minor,patch'],
            'packages.*.dependency_group' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_url.required' => '仓库 URL 不能为空',
            'source_url.url' => '仓库 URL 格式不正确',
            'source_owner.required' => '仓库所有者不能为空',
            'source_repo.required' => '仓库名称不能为空',
            'packages.required' => '依赖列表不能为空',
            'packages.min' => '至少需要一个依赖',
            'packages.max' => '单次最多添加 500 个依赖',
            'packages.*.ecosystem.required' => '依赖生态不能为空',
            'packages.*.ecosystem.in' => '依赖生态只支持 npm 或 composer',
            'packages.*.package_name.required' => '包名不能为空',
            'packages.*.package_name.max' => '包名长度不能超过 200 个字符',
            'packages.*.watch_level.required' => '关注级别不能为空',
            'packages.*.watch_level.in' => '关注级别只支持 major、minor 或 patch',
        ];
    }
}
