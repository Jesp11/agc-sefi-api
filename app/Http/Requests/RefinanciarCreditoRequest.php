<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefinanciarCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_otorgado' => 'required|numeric|min:0.01',
            'fecha_primer_pago' => 'required|date',
            'fecha_otorgacion' => 'nullable|date',
            'plazos' => 'required|integer|min:1|max:104',
            'valor_ficha' => 'required|numeric|min:0.01',
            'total' => 'required|numeric|min:0.01',
            'interes' => 'nullable|numeric|min:0',
            'porcentaje_interes' => 'nullable|numeric|min:0|max:100',
            'dias_pago' => 'nullable|string|max:20',
            'tasa_asignada' => 'nullable|string|max:50',
            'comision_apertura' => 'nullable|numeric|min:0',
            'intereses_arrastrados' => 'nullable|numeric|min:0',
            'notas' => 'nullable|string|max:500',
        ];
    }
}
