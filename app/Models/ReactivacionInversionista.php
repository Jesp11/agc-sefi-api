<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReactivacionInversionista extends Model
{
    protected $table = 'reactivaciones_inversionistas';

    protected $fillable = [
        'inversionista_id', 'fecha', 'motivo', 'realizado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function inversionista() { return $this->belongsTo(Inversionista::class); }
    public function realizadoPor() { return $this->belongsTo(User::class, 'realizado_por'); }
}
