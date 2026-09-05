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

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
