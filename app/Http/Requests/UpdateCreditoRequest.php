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
            'id_cliente' => 'sometimes|nullable|string|exists:clientes,id_cliente',
            'id_grupo' => 'sometimes|nullable|integer|exists:grupos,id',
            'fecha_otorgacion' => 'sometimes|date',
            'fecha_primer_pago' => 'sometimes|nullable|date',
            'ciclo' => 'sometimes|integer|min:1',
            'ciclo_inicio_mora' => 'sometimes|nullable|integer|min:1',
            'monto_otorgado' => 'sometimes|numeric|min:0',
            'interes' => 'sometimes|numeric|min:0',
            'total' => 'sometimes|numeric|min:0',
            'saldo_pendiente' => 'sometimes|nullable|numeric|min:0',
            'abonos_historicos' => 'sometimes|nullable|numeric|min:0',
            'plazos' => 'sometimes|integer|min:1',
            'valor_ficha' => 'sometimes|numeric|min:0',
            'dias_pago' => 'sometimes|string|max:255',
            'comision_apertura' => 'sometimes|nullable|numeric|min:0',
            'tasa_asignada' => 'sometimes|nullable|string|max:50',
            'porcentaje_interes' => 'sometimes|nullable|numeric|min:0',
            'tabla_amortizacion' => 'sometimes|nullable|array',
            'abono_recuperacion' => 'sometimes|nullable|numeric|min:0',
            'estado' => 'sometimes|in:Activo,Finalizado,Cancelado,EnMora,CerradoSinRenovacion',
            'es_personalizado' => 'sometimes|boolean',
            'es_adicional' => 'sometimes|boolean',
            'ubicacion_expediente' => 'sometimes|nullable|string|max:255',
            'notas_expediente' => 'sometimes|nullable|string',
            'fecha_programada_renovacion' => 'sometimes|nullable|date',
            'renovacion_autorizada' => 'sometimes|nullable|string|max:50',
            'renovacion_tasa' => 'sometimes|nullable|string|max:50',
        ];
    }
}
