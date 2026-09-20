<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => 'sometimes|string|max:255|unique:roles,name,'.$role->id,
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ];
    }
}
