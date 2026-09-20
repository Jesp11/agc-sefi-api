<?php

namespace App\Support;

final class DiaPago
{
    public const HABILES = [
        'LUNES',
        'MARTES',
        'MIERCOLES',
        'JUEVES',
        'VIERNES',
    ];

    /**
     * Convierte las distintas formas escritas de un día a un valor canónico.
     */
    public static function normalizar(?string $dia): ?string
    {
        if ($dia === null) {
            return null;
        }

        $normalizado = trim($dia);
        if ($normalizado === '') {
            return '';
        }

        $normalizado = strtr($normalizado, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);

        return strtoupper($normalizado);
    }
}
