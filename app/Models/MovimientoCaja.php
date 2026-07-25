<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoCaja extends Model
{
    protected $table = 'movimientos_caja';

    protected $fillable = [
        'fecha',
        'id_asesor',
        'motivo',
        'tipo',
        'monto',
        'saldo_resultante',
        'categoria',
        'cuenta',
        'num_prog',
        'pago_id',
        'referencia',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'saldo_resultante' => 'decimal:2',
    ];

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }

    public function pago()
    {
        return $this->belongsTo(Pago::class, 'pago_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
