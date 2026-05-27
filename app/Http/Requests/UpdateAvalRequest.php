<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAvalRequest extends FormRequest
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
        return [
            'id_cliente' => 'sometimes|string|exists:clientes,id_cliente',
            'nombre' => 'sometimes|string|max:255',
            'direccion' => 'sometimes|string',
            'telefono' => 'sometimes|string|max:20',
            'parentesco' => 'sometimes|string|max:255',
            'tiempo_conocer' => 'sometimes|nullable|string|max:100',
            'ocupacion_laboral' => 'sometimes|nullable|string|max:255',
            'empresa' => 'sometimes|nullable|string|max:255',
        ];
    }
}
