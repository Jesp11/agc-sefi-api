<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asesor extends Model
{
    use HasFactory;

    protected $table = 'asesores';

    protected $fillable = [
        'nombre_asesor',
    ];

    public function creditos()
    {
        return $this->hasMany(Credito::class, 'id_asesor');
    }

    public function clientes()
    {
        return $this->hasMany(Cliente::class, 'id_asesor');
    }
}
