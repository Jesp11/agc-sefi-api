<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClienteRequest extends FormRequest
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
        $clienteId = $this->route('cliente'); // Assuming the parameter name is 'cliente'

        return [
            'id_asesor' => 'sometimes|integer|exists:asesores,id',
            'nombre_completo' => 'sometimes|string|max:255',
            'curp' => 'sometimes|string|size:18|unique:clientes,curp,' . $clienteId . ',id_cliente',
            'clave_elector' => 'sometimes|string|max:255',
            'telefono' => 'sometimes|string|max:20',
            'direccion' => 'sometimes|string',
            'entre_calles' => 'sometimes|string|max:255',
            'ocupacion' => 'sometimes|string|max:255',
            'direccion_trabajo' => 'sometimes|string',
            'telefono_trabajo' => 'sometimes|string|max:20',
            'id_grupo' => 'sometimes|integer|exists:grupos,id',
        ];
    }
}
