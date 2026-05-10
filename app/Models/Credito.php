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
        'id_asesor',
        'fecha_otorgacion',
        'ciclo',
        'monto_otorgado',
        'interes',
        'total',
        'plazos',
        'valor_ficha',
        'dias_pago',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }
}
