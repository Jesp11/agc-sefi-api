<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditoGrupalDistribucion extends Model
{
    use HasFactory;

    protected $table = 'credito_grupal_distribuciones';

    protected $fillable = [
        'num_prog', 'id_cliente', 'nombre_cliente', 'curp', 'clave_elector',
        'telefono', 'direccion', 'capital', 'interes', 'total', 'valor_ficha',
        'orden', 'folio_documental',
    ];

    protected $casts = [
        'capital' => 'decimal:2',
        'interes' => 'decimal:2',
        'total' => 'decimal:2',
        'valor_ficha' => 'decimal:2',
    ];

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }

    /** Cliente vigente sólo para complementar datos no históricos al visualizar. */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
