<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCreditoRequest extends FormRequest
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
            'id_cliente' => 'required_without:id_grupo|nullable|string|exists:clientes,id_cliente',
            'id_grupo' => 'required_without:id_cliente|nullable|integer|exists:grupos,id',
            'fecha_otorgacion' => 'required|date',
            'fecha_primer_pago' => 'nullable|date',
            'monto_otorgado' => 'required|numeric|min:0',
            'interes' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'plazos' => 'required|integer|min:1',
            'valor_ficha' => 'required|numeric|min:0',
            'dias_pago' => 'required|string|max:255',
            'es_personalizado' => 'nullable|boolean',
            'tasa_asignada' => 'nullable|string|max:50',
            'porcentaje_interes' => 'nullable|numeric|min:0',
            'tabla_amortizacion' => 'nullable|array',
        ];
    }
}

