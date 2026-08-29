<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto' => 'required|numeric|min:0.01',
            'monto_multa' => 'nullable|numeric|min:0',
            'fecha' => 'required|date',
            'hora' => 'nullable|date_format:H:i:s',
            'metodo_pago' => 'in:Efectivo,Transferencia,Otro',
            'tipo' => 'nullable|in:Abono,Multa',
            'notas' => 'nullable|string|max:500',
            'ahorro_personal_monto' => 'nullable|numeric|min:0',
        ];
    }
}
