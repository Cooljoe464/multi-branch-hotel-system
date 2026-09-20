<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Global Admin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'app_name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,svg,webp|max:2048',
        ];
    }
}
