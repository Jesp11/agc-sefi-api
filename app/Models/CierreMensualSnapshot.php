<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CierreMensualSnapshot extends Model
{
    protected $table = 'cierre_mensual_snapshots';

    protected $fillable = ['mes', 'cartera_total', 'mora_activa', 'mora_muerta', 'capturado_en'];

    protected $casts = [
        'cartera_total' => 'decimal:2',
        'mora_activa' => 'decimal:2',
        'mora_muerta' => 'decimal:2',
        'capturado_en' => 'datetime',
    ];
}
