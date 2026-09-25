<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GestorGeneralAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('creditos', function (Blueprint $table) {
            $table->id('num_prog');
            $table->string('estado');
            $table->string('tipo_credito');
            $table->unsignedBigInteger('id_asesor');
        });
    }

    private function userWithRole(string $name, ?int $asesorId = null): User
    {
        $user = new User(['id_asesor' => $asesorId]);
        $user->id = 1;
        $user->setRelation('role', new Role(['nombre' => $name]));
        return $user;
    }

    public function test_field_roles_cannot_access_global_search_or_general_portfolio(): void
    {
        foreach (['Gestor de Cobranza', 'asesor', 'Asesor Financiero'] as $role) {
            foreach ([null, 7] as $asesorId) {
                $this->actingAs($this->userWithRole($role, $asesorId), 'api');
                $this->getJson('/api/busqueda-global?q=')->assertForbidden();
                $this->getJson('/api/busqueda-global?q=cliente')->assertForbidden();
                $this->getJson('/api/cartera/activa')->assertForbidden();
                $this->getJson('/api/cartera/activa?tipo=todos')->assertForbidden();
            }
        }
    }

    public function test_field_roles_keep_access_to_individual_and_group_portfolios(): void
    {
        $this->actingAs($this->userWithRole('Gestor de Cobranza', 7), 'api');
        $this->getJson('/api/cartera/activa?tipo=individual')->assertOk();
        $this->getJson('/api/cartera/activa?tipo=grupal')->assertOk();
    }

    public function test_administrative_roles_keep_general_access(): void
    {
        foreach (['admin', 'Administrador', 'Administración', 'Gerencia', 'Contabilidad'] as $role) {
            $this->actingAs($this->userWithRole($role), 'api');
            $this->getJson('/api/busqueda-global?q=')->assertOk();
            $this->getJson('/api/cartera/activa')->assertOk();
        }
    }
}
