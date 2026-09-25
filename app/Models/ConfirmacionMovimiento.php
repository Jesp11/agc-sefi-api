<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfirmacionMovimiento extends Model
{
    public const CATEGORIAS_DESEMBOLSO = ['Renovacion', 'Desembolso'];

    /**
     * Estados en los que el movimiento todavía no se confirma ni se cancela:
     * falta la autorización, la entrega del gestor o cerrar su reintegro.
     */
    public const ESTADOS_EN_PROCESO = ['Pendiente', 'EntregadoGestor', 'PendienteReintegro', 'Reintegrado'];

    /**
     * Estados en los que el efectivo ya salió de caja y el flujo no ha cerrado
     * (entrega del gestor, reintegro o reprogramación pendientes). Eliminar el
     * crédito en estos estados dejaría dinero sin respaldo en cartera.
     */
    public const ESTADOS_BLOQUEAN_ELIMINACION = ['EntregadoGestor', 'PendienteReintegro', 'Reintegrado'];

    private const DESCRIPCION_ESTADOS = [
        'Pendiente' => 'pendiente de confirmar',
        'EntregadoGestor' => 'pendiente de confirmar entrega por el gestor',
        'PendienteReintegro' => 'pendiente de confirmar el reintegro',
        'Reintegrado' => 'reintegrado, pendiente de reprogramar o cancelar',
    ];

    public function descripcionEstado(): string
    {
        return self::DESCRIPCION_ESTADOS[$this->estado] ?? mb_strtolower((string) $this->estado);
    }

    public function requiereConfirmacionGestor(): bool
    {
        return in_array(mb_strtolower((string) $this->categoria), ['renovacion', 'desembolso'], true);
    }

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
    public function liquidacionInversionista() { return $this->hasOne(LiquidacionInversionista::class); }
}
