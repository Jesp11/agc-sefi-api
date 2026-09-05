<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Credito extends Model
{
    use HasFactory;

    protected $table = 'creditos';
    protected $primaryKey = 'num_prog';

    protected $fillable = [
        'id_cliente',
        'id_grupo',
        'id_asesor',
        'fecha_otorgacion',
        'fecha_primer_pago',
        'ciclo',
        'ciclo_inicio_mora',
        'abono_recuperacion',
        'comision_apertura',
        'monto_otorgado',
        'interes',
        'total',
        'saldo_pendiente',
        'plazos',
        'valor_ficha',
        'dias_pago',
        'tipo_credito',
        'estado',
        'es_personalizado',
        'es_adicional',
        'tasa_asignada',
        'porcentaje_interes',
        'tabla_amortizacion',
        'credito_padre_id',
        'dias_mora_cache',
        'ubicacion_expediente',
        'notas_expediente',
        'fecha_programada_renovacion',
        'renovacion_autorizada',
        'renovacion_tasa',
    ];

    protected $casts = [
        'tabla_amortizacion' => 'array',
        'porcentaje_interes' => 'decimal:2',
        'es_personalizado' => 'boolean',
        'es_adicional' => 'boolean',
        'comision_apertura' => 'decimal:2',
        'saldo_pendiente' => 'decimal:2',
        'abono_recuperacion' => 'decimal:2',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function grupo()
    {
        return $this->belongsTo(Grupo::class, 'id_grupo');
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'num_prog', 'num_prog');
    }

    public function creditoPadre()
    {
        return $this->belongsTo(Credito::class, 'credito_padre_id', 'num_prog');
    }

    public function creditosHijos()
    {
        return $this->hasMany(Credito::class, 'credito_padre_id', 'num_prog');
    }

    public function refinanciamientos()
    {
        return $this->hasMany(Refinanciamiento::class, 'num_prog_nuevo', 'num_prog');
    }

    /** Renovaciones en las que este crédito fue el crédito sustituido. */
    public function refinanciamientosComoAnterior()
    {
        return $this->hasMany(Refinanciamiento::class, 'num_prog_anterior', 'num_prog');
    }

    public function documentos()
    {
        return $this->hasMany(DocumentoCredito::class, 'num_prog', 'num_prog');
    }
}
