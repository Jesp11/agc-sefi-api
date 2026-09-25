<?php

namespace App\Models;

use App\Support\CalendarioQuincenal;
use App\Support\DiaPago;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Credito extends Model
{
    use HasFactory;

    public const FRECUENCIA_SEMANAL = 'Semanal';
    public const FRECUENCIA_QUINCENAL = 'Quincenal';
    public const FRECUENCIAS = [self::FRECUENCIA_SEMANAL, self::FRECUENCIA_QUINCENAL];

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
        'abonos_historicos',
        'plazos',
        'valor_ficha',
        'dias_pago',
        'frecuencia_pago',
        'dia_quincena_1',
        'dia_quincena_2',
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
        'abonos_historicos' => 'decimal:2',
        'abono_recuperacion' => 'decimal:2',
    ];

    /** Garantiza el mismo formato incluso en importaciones y procesos internos. */
    protected function diasPago(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => DiaPago::normalizar($value),
        );
    }

    public function esQuincenal(): bool
    {
        return $this->frecuencia_pago === self::FRECUENCIA_QUINCENAL;
    }

    /** @return array{int, int} Días del mes en que vencen los pagos quincenales. */
    public function diasQuincena(): array
    {
        return [
            (int) ($this->dia_quincena_1 ?: CalendarioQuincenal::DIA_1_DEFAULT),
            (int) ($this->dia_quincena_2 ?: CalendarioQuincenal::DIA_2_DEFAULT),
        ];
    }

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

    public function confirmacionesMovimientos()
    {
        return $this->hasMany(ConfirmacionMovimiento::class, 'num_prog', 'num_prog');
    }

    /**
     * Movimiento de caja del crédito que aún no se confirma ni se cancela.
     * Mientras exista, editar el crédito dejaría caja y cartera desincronizadas.
     */
    public function movimientoEnProceso(): ?ConfirmacionMovimiento
    {
        return $this->confirmacionesMovimientos()
            ->whereIn('estado', ConfirmacionMovimiento::ESTADOS_EN_PROCESO)
            ->latest('id')
            ->first();
    }

    public function distribucionesIntegrantes()
    {
        return $this->hasMany(CreditoGrupalDistribucion::class, 'num_prog', 'num_prog')->orderBy('orden');
    }
}
