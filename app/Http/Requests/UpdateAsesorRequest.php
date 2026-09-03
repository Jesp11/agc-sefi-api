<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAsesorRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $asesorId = $this->route('asesore');

        return [
            'nombre_asesor' => 'sometimes|string|max:255',
            'curp'          => 'sometimes|string|size:18|unique:asesores,curp,' . $asesorId . ',id',
            'telefono'      => 'nullable|string|max:20',
            'rol_laboral'   => 'nullable|string|max:100',
            'ine'           => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'ine_2'         => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'delete_ine'    => 'nullable|boolean',
            'delete_ine_2'  => 'nullable|boolean',
            
            'rfc' => 'nullable|string|max:255',
            'nss' => 'nullable|string|max:255',
            'banco' => 'nullable|string|max:255',
            'cuenta_bancaria' => 'nullable|string|max:255',
            'sueldo_base' => 'nullable|numeric|min:0',
            'despensa' => 'nullable|numeric|min:0',
            'apoyo_transporte' => 'nullable|numeric|min:0',
            'bono_productividad' => 'nullable|numeric|min:0',
            'aportacion_socio' => 'nullable|numeric|min:0',
            'activo' => 'nullable|boolean',
        ];
    }
}
