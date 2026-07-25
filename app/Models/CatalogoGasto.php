<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoGasto extends Model
{
    protected $table = 'catalogo_gastos';

    protected $fillable = [
        'concepto', 'categoria', 'monto_sugerido', 'activo',
    ];

    protected $casts = [
        'monto_sugerido' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function gastos()
    {
        return $this->hasMany(GastoOperativo::class, 'catalogo_gasto_id');
    }
}
