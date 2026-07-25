<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GastoOperativo extends Model
{
    protected $table = 'gastos_operativos';

    protected $fillable = [
        'concepto', 'monto', 'fecha', 'categoria', 'catalogo_gasto_id', 'registrado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function catalogo()
    {
        return $this->belongsTo(CatalogoGasto::class, 'catalogo_gasto_id');
    }
}
