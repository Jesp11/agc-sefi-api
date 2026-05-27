<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_asesor' => 'required_without:id_grupo|nullable|integer|exists:asesores,id',
            'nombre_completo' => 'required|string|max:255',
            'curp' => 'required|string|size:18|unique:clientes,curp',
            'clave_elector' => 'required|string|max:255',
            'telefono' => 'required|string|max:20',
            'direccion' => 'required|string',
            'entre_calles' => 'required|string|max:255',
            'ocupacion' => 'required|string|max:255',
            'direccion_trabajo' => 'required|string',
            'telefono_trabajo' => 'required|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'id_grupo' => 'sometimes|integer|exists:grupos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'id_asesor.required_without' => 'Selecciona un asesor antes de guardar el cliente.',
            'id_asesor.integer'          => 'El asesor seleccionado no es válido.',
            'id_asesor.exists'           => 'El asesor seleccionado no existe.',
        ];
    }
}
