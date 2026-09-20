<?php

namespace App\Http\Requests;

use App\Support\DiaPago;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('dias_pago')) {
            $this->merge(['dias_pago' => DiaPago::normalizar($this->input('dias_pago'))]);
        }
    }

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
            'id_cliente' => 'required_without:id_grupo|nullable|string|exists:clientes,id_cliente',
            'id_grupo' => 'required_without:id_cliente|nullable|integer|exists:grupos,id',
            'fecha_otorgacion' => 'required|date',
            'fecha_primer_pago' => 'nullable|date',
            'monto_otorgado' => 'required|numeric|min:0',
            'interes' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
            'plazos' => 'required|integer|min:1',
            'valor_ficha' => 'required|numeric|min:0',
            'dias_pago' => ['required', 'string', Rule::in(DiaPago::HABILES)],
            'es_personalizado' => 'nullable|boolean',
            'es_adicional' => 'nullable|boolean',
            'comision_apertura' => 'nullable|numeric|min:0',
            'abono_recuperacion' => 'nullable|numeric|min:0',
            'tasa_asignada' => 'nullable|string|max:50',
            'porcentaje_interes' => 'nullable|numeric|min:0',
            'tabla_amortizacion' => 'nullable|array',
            'distribucion_integrantes' => 'required_with:id_grupo|array|min:1',
            'distribucion_integrantes.*.id_cliente' => 'required|string|exists:clientes,id_cliente',
            'distribucion_integrantes.*.capital' => 'required|numeric|gt:0',
        ];
    }
}
