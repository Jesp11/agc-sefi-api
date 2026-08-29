<?php

namespace App\Support;

final class RoleHelper
{
    public const ADMIN_EQUIVALENTS = ['admin', 'Administrador', 'Gerencia', 'Contabilidad'];

    public const FIELD_EQUIVALENTS = ['asesor', 'Gestor de Cobranza', 'Asesor Financiero'];

    public static function isAdminLike(?string $roleName): bool
    {
        return in_array($roleName, self::ADMIN_EQUIVALENTS, true);
    }

    public static function isFieldLike(?string $roleName): bool
    {
        return in_array($roleName, self::FIELD_EQUIVALENTS, true);
    }

    public static function expands(array $roles): array
    {
        $expanded = [];

        foreach ($roles as $role) {
            if ($role === 'admin') {
                array_push($expanded, ...self::ADMIN_EQUIVALENTS);
                continue;
            }

            if ($role === 'asesor') {
                array_push($expanded, ...self::FIELD_EQUIVALENTS);
                continue;
            }

            $expanded[] = $role;
        }

        return array_values(array_unique($expanded));
    }
}
