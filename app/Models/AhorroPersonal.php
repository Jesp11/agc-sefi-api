<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroPersonal extends Model
{
    protected $table = 'ahorros_personal';

    protected $fillable = ['asesor_id', 'saldo'];

    protected $casts = ['saldo' => 'decimal:2'];

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'asesor_id');
    }

    public function movimientos()
    {
        return $this->hasMany(AhorroPersonalMovimiento::class, 'ahorro_personal_id');
    }
}
