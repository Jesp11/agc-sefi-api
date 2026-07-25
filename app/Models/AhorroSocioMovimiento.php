<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroSocioMovimiento extends Model
{
    protected $table = 'ahorro_socio_movimientos';

    protected $fillable = ['ahorro_socio_id', 'tipo', 'monto', 'fecha', 'notas', 'registrado_por'];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function ahorroSocio()
    {
        return $this->belongsTo(AhorroSocio::class, 'ahorro_socio_id');
    }
}
