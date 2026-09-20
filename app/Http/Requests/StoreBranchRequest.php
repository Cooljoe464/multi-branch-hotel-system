<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
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
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:branches,code',
            'address' => 'nullable|string|max:500',
            'city' => 'required|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'required|string|size:2',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'timezone' => 'required|string|max:50',
            'currency_code' => 'required|string|size:3',
            'currency_symbol' => 'required|string|max:5',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'tax_label' => 'required|string|max:50',
            'is_primary' => 'boolean',
            'room_types' => 'required|array|min:1',
            'room_types.*.name' => 'required|string|max:255',
            'room_types.*.code' => 'required|string|max:10',
            'room_types.*.description' => 'nullable|string|max:500',
            'room_types.*.base_rate' => 'required|integer|min:0',
            'room_types.*.max_occupancy' => 'required|integer|min:1|max:20',
            'room_types.*.bed_count' => 'required|integer|min:1|max:10',
            'room_types.*.bed_type' => 'required|string|in:single,queen,king,twin,sofa',
            'floors' => 'required|array|min:1',
            'floors.*' => 'required|string|max:10',
            'rooms' => 'required|array|min:1',
            'rooms.*.number' => 'required|string|max:10',
            'rooms.*.floor' => 'required|string|max:10',
            'rooms.*.room_type_code' => 'required|string|max:10',
            'rooms.*.wing' => 'nullable|string|max:50',
        ];
    }
}
