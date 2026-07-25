<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroPersonalMovimiento extends Model
{
    protected $table = 'ahorro_personal_movimientos';

    protected $fillable = ['ahorro_personal_id', 'tipo', 'monto', 'fecha', 'notas', 'registrado_por'];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function ahorroPersonal()
    {
        return $this->belongsTo(AhorroPersonal::class, 'ahorro_personal_id');
    }
}
