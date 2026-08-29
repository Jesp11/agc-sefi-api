<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAsesorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre_asesor' => 'required|string|max:255',
            'curp'          => 'required|string|size:18|unique:asesores,curp',
            'telefono'      => 'nullable|string|max:20',
            'rol_laboral'   => 'nullable|string|max:100',
            'ine'           => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'ine_2'         => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
