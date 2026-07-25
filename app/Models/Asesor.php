<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asesor extends Model
{
    use HasFactory;

    protected $table = 'asesores';

    protected $fillable = [
        'id_asesor',
        'nombre_asesor',
        'curp',
        'cumpleanos',
        'telefono',
        'ine_path',
        'ine_path_2',
    ];

    public function creditos()
    {
        return $this->hasMany(Credito::class, 'id_asesor');
    }

    public function clientes()
    {
        return $this->hasMany(Cliente::class, 'id_asesor');
    }

    public function ahorroPersonal()
    {
        return $this->hasOne(AhorroPersonal::class, 'asesor_id');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id_asesor');
    }
}
