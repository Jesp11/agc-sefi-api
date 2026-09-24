<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiquidacionInversionista extends Model
{
    public const PENDIENTE = 'Pendiente';
    public const CONFIRMADA = 'Confirmada';
    public const CANCELADA = 'Cancelada';

    protected $table = 'liquidaciones_inversionistas';

    protected $fillable = [
        'inversionista_id', 'capital', 'rendimiento_final', 'total', 'fecha', 'cuenta', 'notas', 'estado',
        'confirmacion_movimiento_id', 'aportacion_retiro_id', 'aportacion_rendimiento_id',
        'movimiento_capital_retiro_id', 'movimiento_capital_rendimiento_id',
        'solicitado_por', 'confirmado_por', 'confirmado_at',
    ];

    protected $casts = [
        'capital' => 'decimal:2',
        'rendimiento_final' => 'decimal:2',
        'total' => 'decimal:2',
        'fecha' => 'date',
        'confirmado_at' => 'datetime',
    ];

    public function inversionista() { return $this->belongsTo(Inversionista::class); }
    public function confirmacionMovimiento() { return $this->belongsTo(ConfirmacionMovimiento::class); }
    public function solicitadoPor() { return $this->belongsTo(User::class, 'solicitado_por'); }
    public function confirmadoPor() { return $this->belongsTo(User::class, 'confirmado_por'); }
}
