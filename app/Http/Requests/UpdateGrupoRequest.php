<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGrupoRequest extends FormRequest
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
            'nombre_grupo' => 'sometimes|string|max:255',
            'id_asesor' => 'sometimes|integer|exists:asesores,id',
            'clientes' => 'sometimes|array',
            'clientes.*' => 'string|exists:clientes,id_cliente',
        ];
    }
}
