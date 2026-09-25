<?php

namespace App\Http\Requests;

use App\Models\Credito;
use App\Support\CalendarioQuincenal;
use App\Support\DiaPago;
use Illuminate\Validation\Validator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('dias_pago'))) {
            $this->merge(['dias_pago' => DiaPago::normalizar($this->input('dias_pago'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('frecuencia_pago') !== Credito::FRECUENCIA_QUINCENAL || $validator->errors()->isNotEmpty()) {
                return;
            }

            $primerPago = $this->input('fecha_primer_pago');
            $dia1 = (int) $this->input('dia_quincena_1');
            $dia2 = (int) $this->input('dia_quincena_2');

            if (!$primerPago) {
                $validator->errors()->add('fecha_primer_pago', 'La fecha de primer pago es obligatoria en créditos quincenales.');
            } elseif (!CalendarioQuincenal::esDiaDePago($primerPago, $dia1, $dia2)) {
                $validator->errors()->add('fecha_primer_pago', "La fecha de primer pago debe caer en día {$dia1} o {$dia2} del mes.");
            }
        });
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
            // En quincenales el día de pago se deriva de los días del mes.
            'dias_pago' => ['required_unless:frecuencia_pago,'.Credito::FRECUENCIA_QUINCENAL, 'nullable', 'string', Rule::in(DiaPago::HABILES)],
            'frecuencia_pago' => ['nullable', 'string', Rule::in(Credito::FRECUENCIAS)],
            'dia_quincena_1' => ['required_if:frecuencia_pago,'.Credito::FRECUENCIA_QUINCENAL, 'nullable', 'integer', 'between:1,31'],
            'dia_quincena_2' => ['required_if:frecuencia_pago,'.Credito::FRECUENCIA_QUINCENAL, 'nullable', 'integer', 'between:1,31', 'gt:dia_quincena_1'],
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
