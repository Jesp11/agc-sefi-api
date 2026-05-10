<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
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
            'id_grupo' => 'sometimes|integer|exists:grupos,id',
        ];
    }
}
