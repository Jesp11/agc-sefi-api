<?php

namespace App\Support;

final class RoleHelper
{
    public const ADMIN_EQUIVALENTS = ['admin', 'Administrador', 'Administración', 'Gerencia', 'Contabilidad'];

    public const FIELD_EQUIVALENTS = ['asesor', 'Gestor de Cobranza', 'Asesor Financiero'];

    public static function isAdminLike(?string $roleName): bool
    {
        if ($roleName === null || $roleName === '') {
            return false;
        }

        $normalized = mb_strtolower(trim($roleName), 'UTF-8');
        $adminEquivalents = array_map(fn ($r) => mb_strtolower($r, 'UTF-8'), self::ADMIN_EQUIVALENTS);

        return in_array($normalized, $adminEquivalents, true);
    }

    public static function isFieldLike(?string $roleName): bool
    {
        if ($roleName === null || $roleName === '') {
            return false;
        }

        $normalized = mb_strtolower(trim($roleName), 'UTF-8');
        $fieldEquivalents = array_map(fn ($r) => mb_strtolower($r, 'UTF-8'), self::FIELD_EQUIVALENTS);

        return in_array($normalized, $fieldEquivalents, true);
    }

    public static function expands(array $roles): array
    {
        $expanded = [];

        foreach ($roles as $role) {
            if (self::isAdminLike($role)) {
                array_push($expanded, ...self::ADMIN_EQUIVALENTS);
                continue;
            }

            if (self::isFieldLike($role)) {
                array_push($expanded, ...self::FIELD_EQUIVALENTS);
                continue;
            }

            $expanded[] = $role;
        }

        return array_values(array_unique($expanded));
    }
}
