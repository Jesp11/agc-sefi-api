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
        'ciclo',
        'monto_otorgado',
        'interes',
        'total',
        'plazos',
        'valor_ficha',
        'dias_pago',
        'tipo_credito',
        'estado',
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
}
