<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inversionista extends Model
{
    protected $table = 'inversionistas';

    protected $fillable = [
        'nombre', 'tipo_entidad', 'origen_fondeo', 'contacto', 'telefono', 'email', 'tasa_preferencial',
        'tasa_mensual', 'activo',
    ];

    protected $casts = [
        'tasa_preferencial' => 'boolean',
        'tasa_mensual' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function calcularRendimientoMensual(float $saldoCapital): float
    {
        return round(max(0, $saldoCapital) * ((float) $this->tasa_mensual / 100), 2);
    }

    public function aportaciones()
    {
        return $this->hasMany(Aportacion::class);
    }

    public function liquidaciones()
    {
        return $this->hasMany(LiquidacionInversionista::class)->orderByDesc('id');
    }

    public function reactivaciones()
    {
        return $this->hasMany(ReactivacionInversionista::class)->orderByDesc('id');
    }
}
