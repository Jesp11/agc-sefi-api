<?php

namespace App\Services;

use App\Models\Asesor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AsesorService
{
    public function getPrefixByRole(?string $rolLaboral): string
    {
        if (empty($rolLaboral)) {
            return 'GC';
        }

        $normalized = mb_strtoupper(trim($rolLaboral), 'UTF-8');

        return match (true) {
            str_contains($normalized, 'GESTOR') || str_contains($normalized, 'COBRANZA') => 'GC',
            str_contains($normalized, 'FINANCIERO') || str_contains($normalized, 'ASESOR') => 'AF',
            str_contains($normalized, 'ADMIN') => 'AD',
            str_contains($normalized, 'GEREN') => 'GE',
            str_contains($normalized, 'CONTAB') => 'CO',
            default => 'EMP',
        };
    }

    public function generateIdAsesor(string $nombre, ?string $rolLaboral = null): string
    {
        $rolePrefix = $this->getPrefixByRole($rolLaboral);

        $words = explode(' ', strtoupper(trim($nombre)));
        $initials = '';
        foreach ($words as $word) {
            if (! empty($word)) {
                $initials .= substr($word, 0, 1);
            }
        }
        $prefix = $rolePrefix . $initials;

        $lastAsesor = Asesor::where('id_asesor', 'like', $prefix . '%')
            ->orderBy('id_asesor', 'desc')
            ->first();

        if ($lastAsesor) {
            $number = (int) substr($lastAsesor->id_asesor, strlen($prefix));
            $newNumber = $number + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . str_pad((string) $newNumber, 3, '0', STR_PAD_LEFT);
    }

    public function cumpleanosFromCurp(string $curp): string
    {
        $curp = strtoupper($curp);
        $year = substr($curp, 4, 2);
        $month = substr($curp, 6, 2);
        $day = substr($curp, 8, 2);
        $currentYear = (int) date('y');
        $fullYear = ((int) $year > $currentYear) ? '19' . $year : '20' . $year;

        return $fullYear . '-' . $month . '-' . $day;
    }

    public function create(array $data): Asesor
    {
        $data['rol_laboral'] = $data['rol_laboral'] ?? 'Gestor de Cobranza';
        $data['id_asesor'] = $this->generateIdAsesor($data['nombre_asesor'], $data['rol_laboral']);
        $data['curp'] = strtoupper($data['curp']);
        $data['cumpleanos'] = $this->cumpleanosFromCurp($data['curp']);

        return Asesor::create($data);
    }

    /**
     * @return array{asesor: Asesor, action: 'created'|'updated'}
     */
    public function upsertFromImport(array $data): array
    {
        $curp = strtoupper($data['curp']);
        $asesor = Asesor::where('curp', $curp)->first();

        $payload = [
            'nombre_asesor' => $data['nombre_asesor'],
            'cumpleanos' => $this->cumpleanosFromCurp($curp),
            'rol_laboral' => $data['rol_laboral'] ?? 'Gestor de Cobranza',
        ];

        if (array_key_exists('telefono', $data)) {
            $payload['telefono'] = $data['telefono'] ?: null;
        }

        if ($asesor) {
            $asesor->update($payload);
            $action = 'updated';
            $savedAsesor = $asesor->fresh();
        } else {
            $payload['curp'] = $curp;
            $savedAsesor = $this->create($payload);
            $action = 'created';
        }

        // Si se proporcionó email en la plantilla, crear o actualizar acceso
        if (!empty($data['email'])) {
            $email = strtolower(trim($data['email']));
            $password = !empty($data['password']) ? trim($data['password']) : null;

            if ($savedAsesor->user()->exists()) {
                $this->actualizarAcceso($savedAsesor, $email, $password);
            } else {
                $this->crearAcceso($savedAsesor, $email, $password);
            }
        }

        return ['asesor' => $savedAsesor->fresh(['user']), 'action' => $action];
    }

    /**
     * Crea usuario de acceso para un asesor (rol asesor) con contraseña temporal.
     *
     * @return array{user: User, password: string, created: bool}
     */
    public function crearAcceso(Asesor $asesor, string $email, ?string $password = null): array
    {
        if ($asesor->user()->exists()) {
            throw new InvalidArgumentException('Este empleado ya tiene acceso al sistema. Usa restablecer contraseña.');
        }

        $email = strtolower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw new InvalidArgumentException('El correo ya está registrado en otro usuario.');
        }

        $role = Role::whereIn('nombre', ['Gestor de Cobranza', 'asesor'])
            ->orderByRaw("CASE WHEN nombre = 'Gestor de Cobranza' THEN 0 ELSE 1 END")
            ->first();
        if (!$role) {
            throw new InvalidArgumentException('No existe el rol operativo de cobranza en el sistema.');
        }

        $plainPassword = $password ?: $this->generarPasswordTemporal();

        $user = User::create([
            'name' => $asesor->nombre_asesor,
            'email' => $email,
            'password' => $plainPassword,
            'role_id' => $role->id,
            'id_asesor' => $asesor->id,
            'email_verified_at' => now(),
        ]);

        return [
            'user' => $user->load('role'),
            'password' => $plainPassword,
            'created' => true,
        ];
    }

    /**
     * Actualiza email y/o genera nueva contraseña temporal.
     *
     * @return array{user: User, password: string|null}
     */
    public function actualizarAcceso(Asesor $asesor, ?string $email = null, ?string $password = null, bool $regenerarPassword = false): array
    {
        $user = $asesor->user;
        if (!$user) {
            throw new InvalidArgumentException('Este empleado no tiene acceso. Crea el acceso primero.');
        }

        $updates = [];

        if ($email !== null && $email !== '') {
            $email = strtolower(trim($email));
            $exists = User::where('email', $email)->where('id', '!=', $user->id)->exists();
            if ($exists) {
                throw new InvalidArgumentException('El correo ya está registrado en otro usuario.');
            }
            $updates['email'] = $email;
        }

        $plainPassword = null;
        if ($regenerarPassword || ($password !== null && $password !== '')) {
            $plainPassword = $password ?: $this->generarPasswordTemporal();
            $updates['password'] = $plainPassword;
        }

        if ($updates !== []) {
            $user->update($updates);
        }

        return [
            'user' => $user->fresh()->load('role'),
            'password' => $plainPassword,
        ];
    }

    public function generarPasswordTemporal(int $length = 10): string
    {
        // Evita caracteres ambiguos (0/O, 1/l/I)
        return Str::password($length, symbols: false);
    }
}
