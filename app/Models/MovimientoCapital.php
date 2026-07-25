<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoCapital extends Model
{
    protected $table = 'movimientos_capital';

    protected $fillable = [
        'tipo', 'monto', 'referencia', 'fecha', 'descripcion', 'registrado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];
}
