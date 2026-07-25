<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('password');
            $table->unsignedBigInteger('id_asesor')->nullable()->after('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('id_asesor')->references('id')->on('asesores')->nullOnDelete();
        });

        $adminRoleId = DB::table('roles')->insertGetId(['nombre' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $asesorRoleId = DB::table('roles')->insertGetId(['nombre' => 'asesor', 'created_at' => now(), 'updated_at' => now()]);

        $permissions = [
            'creditos.view', 'creditos.create', 'creditos.update',
            'clientes.view', 'clientes.create', 'clientes.update',
            'reportes.global', 'reportes.asesor',
            'inversionistas.manage', 'contabilidad.manage', 'nomina.manage',
        ];

        foreach ($permissions as $perm) {
            $permId = DB::table('permissions')->insertGetId(['nombre' => $perm, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permission')->insert(['role_id' => $adminRoleId, 'permission_id' => $permId]);
            if (in_array($perm, ['creditos.view', 'creditos.create', 'clientes.view', 'clientes.create', 'reportes.asesor'])) {
                DB::table('role_permission')->insert(['role_id' => $asesorRoleId, 'permission_id' => $permId]);
            }
        }

        DB::table('users')->update(['role_id' => $adminRoleId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['id_asesor']);
            $table->dropColumn(['role_id', 'id_asesor']);
        });
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
