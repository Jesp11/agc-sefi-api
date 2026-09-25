<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('nombre', 'inversionistas.manage')->value('id');

        foreach (['Gerencia', 'Contabilidad'] as $roleName) {
            $roleId = DB::table('roles')->where('nombre', $roleName)->value('id');

            if ($roleId !== null && $permissionId !== null) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('nombre', 'inversionistas.manage')->value('id');
        $roleIds = DB::table('roles')->whereIn('nombre', ['Gerencia', 'Contabilidad'])->pluck('id');

        if ($permissionId !== null) {
            DB::table('role_permission')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }
    }
};
