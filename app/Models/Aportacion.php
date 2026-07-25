<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aportacion extends Model
{
    protected $table = 'aportaciones';

    protected $fillable = [
        'inversionista_id', 'monto', 'fecha', 'tipo', 'notas', 'registrado_por',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function inversionista()
    {
        return $this->belongsTo(Inversionista::class);
    }
}
