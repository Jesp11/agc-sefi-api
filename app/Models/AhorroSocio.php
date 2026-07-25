<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AhorroSocio extends Model
{
    protected $table = 'ahorros_socio';

    protected $fillable = ['socio_id', 'saldo'];

    protected $casts = ['saldo' => 'decimal:2'];

    public function socio()
    {
        return $this->belongsTo(Socio::class);
    }

    public function movimientos()
    {
        return $this->hasMany(AhorroSocioMovimiento::class, 'ahorro_socio_id');
    }
}
