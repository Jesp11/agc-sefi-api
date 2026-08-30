<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IndicadorOperativoEvento extends Model
{
    protected $table = 'indicadores_operativos_eventos';

    protected $fillable = [
        'fecha',
        'tipo',
        'monto',
        'num_prog',
        'num_prog_relacionado',
        'origen',
        'descripcion',
        'meta',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
        'meta' => 'array',
    ];

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }

    public function creditoRelacionado()
    {
        return $this->belongsTo(Credito::class, 'num_prog_relacionado', 'num_prog');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
