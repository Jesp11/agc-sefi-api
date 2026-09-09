<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfirmacionMovimiento extends Model
{
    protected $table = 'confirmaciones_movimientos';

    protected $fillable = [
        'fecha', 'id_asesor', 'motivo', 'monto', 'categoria', 'cuenta',
        'num_prog', 'referencia', 'estado', 'movimiento_caja_id',
        'solicitado_por', 'confirmado_por', 'confirmado_at', 'entregado_gestor_por', 'entregado_gestor_at',
        'cancelado_gestor_por', 'cancelado_gestor_at',
        'reintegrado_por', 'reintegrado_at', 'movimiento_reintegro_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'confirmado_at' => 'datetime',
        'entregado_gestor_at' => 'datetime',
        'cancelado_gestor_at' => 'datetime',
        'reintegrado_at' => 'datetime',
    ];

    public function asesor() { return $this->belongsTo(Asesor::class, 'id_asesor'); }
    public function credito() { return $this->belongsTo(Credito::class, 'num_prog', 'num_prog'); }
    public function movimientoCaja() { return $this->belongsTo(MovimientoCaja::class, 'movimiento_caja_id'); }
    public function movimientoReintegro() { return $this->belongsTo(MovimientoCaja::class, 'movimiento_reintegro_id'); }
}
