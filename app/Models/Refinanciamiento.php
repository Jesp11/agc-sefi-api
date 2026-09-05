<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refinanciamiento extends Model
{
    protected $table = 'refinanciamientos';

    protected $fillable = [
        'num_prog_anterior', 'num_prog_nuevo', 'saldo_anterior',
        'deduccion', 'monto_neto', 'intereses_arrastrados', 'fecha_efectiva', 'notas',
    ];

    protected $casts = [
        'saldo_anterior' => 'decimal:2',
        'deduccion' => 'decimal:2',
        'monto_neto' => 'decimal:2',
        'intereses_arrastrados' => 'decimal:2',
        'fecha_efectiva' => 'date',
    ];

    public function creditoAnterior()
    {
        return $this->belongsTo(Credito::class, 'num_prog_anterior', 'num_prog');
    }

    public function creditoNuevo()
    {
        return $this->belongsTo(Credito::class, 'num_prog_nuevo', 'num_prog');
    }
}
