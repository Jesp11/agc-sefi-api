<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditoRequest extends FormRequest
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
            'id_cliente' => 'sometimes|string|exists:clientes,id_cliente',
            'fecha_otorgacion' => 'sometimes|date',
            'monto_otorgado' => 'sometimes|numeric|min:0',
            'interes' => 'sometimes|numeric|min:0',
            'total' => 'sometimes|numeric|min:0',
            'plazos' => 'sometimes|integer|min:1',
            'valor_ficha' => 'sometimes|numeric|min:0',
            'dias_pago' => 'sometimes|string|max:255',
        ];
    }
}
