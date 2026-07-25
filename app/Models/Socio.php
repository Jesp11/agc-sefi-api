<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Socio extends Model
{
    protected $fillable = ['nombre', 'codigo', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function ahorro()
    {
        return $this->hasOne(AhorroSocio::class);
    }
}
