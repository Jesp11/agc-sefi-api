<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroMovimiento extends Model
{
    protected $table = 'ahorro_movimientos';

    protected $fillable = ['ahorro_id', 'tipo', 'monto', 'fecha', 'notas'];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];
}
