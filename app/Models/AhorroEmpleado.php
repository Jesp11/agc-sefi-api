<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroEmpleado extends Model
{
    protected $table = 'ahorros_empleado';

    protected $fillable = ['empleado_id', 'saldo'];

    protected $casts = ['saldo' => 'decimal:2'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function movimientos()
    {
        return $this->hasMany(AhorroMovimiento::class, 'ahorro_id');
    }
}
