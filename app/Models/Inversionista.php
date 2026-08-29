<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inversionista extends Model
{
    protected $table = 'inversionistas';

    protected $fillable = [
        'nombre', 'tipo_entidad', 'origen_fondeo', 'contacto', 'telefono', 'email', 'tasa_preferencial', 'activo',
    ];

    protected $casts = [
        'tasa_preferencial' => 'boolean',
        'activo' => 'boolean',
    ];

    public function aportaciones()
    {
        return $this->hasMany(Aportacion::class);
    }
}
