<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAsesorRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $asesorParam = $this->route('asesore') ?? $this->route('asesor') ?? $this->route('id');
        $asesorId = is_object($asesorParam) ? ($asesorParam->id ?? null) : $asesorParam;

        return [
            'nombre_asesor' => 'sometimes|string|max:255',
            'curp'          => 'sometimes|string|size:18|unique:asesores,curp,' . $asesorId . ',id',
            'telefono'      => 'nullable|string|max:20',
            'ine'           => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'ine_2'         => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'delete_ine'    => 'nullable|boolean',
            'delete_ine_2'  => 'nullable|boolean',
        ];
    }
}
