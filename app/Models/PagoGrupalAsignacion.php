<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoGrupalAsignacion extends Model
{
    protected $table = 'pago_grupal_asignaciones';
    protected $fillable = ['pago_id', 'id_cliente_integrante', 'monto'];
    protected $casts = ['monto' => 'decimal:2'];
}
