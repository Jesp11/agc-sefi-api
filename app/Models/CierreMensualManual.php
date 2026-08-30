<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CierreMensualManual extends Model
{
    protected $table = 'cierre_mensual_manuales';

    protected $fillable = [
        'mes',
        'aumento_cartera',
        'cancelacion_credito_vehicular',
        'pase_a_cartera_mora',
        'productividad_mensual',
        'registrado_por',
    ];

    protected $casts = [
        'aumento_cartera' => 'decimal:2',
        'cancelacion_credito_vehicular' => 'decimal:2',
        'pase_a_cartera_mora' => 'decimal:2',
        'productividad_mensual' => 'decimal:2',
    ];

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
