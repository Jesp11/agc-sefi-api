<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    protected $table = 'pagos';

    protected $fillable = [
        'num_prog',
        'id_cliente_integrante',
        'monto',
        'ahorro_personal_monto',
        'fecha',
        'hora',
        'metodo_pago',
        'tipo',
        'notas',
        'referencia_importacion',
        'registrado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'ahorro_personal_monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }

    /** Integrante al que se aplicó un abono de crédito grupal. */
    public function integrante()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente_integrante', 'id_cliente');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function movimientoCaja()
    {
        return $this->hasOne(MovimientoCaja::class, 'pago_id');
    }

    public function asignacionesGrupales()
    {
        return $this->hasMany(PagoGrupalAsignacion::class, 'pago_id');
    }
}
