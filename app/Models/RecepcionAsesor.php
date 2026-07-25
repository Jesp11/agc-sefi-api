<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecepcionAsesor extends Model
{
    protected $table = 'recepciones_asesor';

    protected $fillable = [
        'fecha',
        'id_asesor',
        'monto_esperado',
        'monto_recibido',
        'notas',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto_esperado' => 'decimal:2',
        'monto_recibido' => 'decimal:2',
    ];

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
