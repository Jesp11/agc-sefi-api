<?php

namespace App\Services;

use App\Models\Asesor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AsesorService
{
    public function resolveExistingFromImport(array $data): ?Asesor
    {
        $curp = isset($data['curp']) ? strtoupper(trim((string) $data['curp'])) : null;
        if ($curp) {
            $byCurp = Asesor::where('curp', $curp)->first();
            if ($byCurp) {
                return $byCurp;
            }
        }

        $nombre = trim((string) ($data['nombre_asesor'] ?? ''));
        if ($nombre === '') {
            return null;
        }

        $normalized = $this->normalizePersonName($nombre);
        $exact = Asesor::all()->first(function (Asesor $asesor) use ($normalized) {
            return $this->normalizePersonName($asesor->nombre_asesor) === $normalized;
        });
        if ($exact) {
            return $exact;
        }

        $asesores = Asesor::all(['id', 'nombre_asesor', 'curp', 'telefono', 'rol_laboral']);
        $matches = $asesores->filter(function (Asesor $asesor) use ($normalized) {
            return $this->nameContainsAllTokens($normalized, $this->normalizePersonName($asesor->nombre_asesor));
        })->values();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $tokens = $this->tokensFromName($normalized);
        if (count($tokens) === 1) {
            $single = $tokens[0];
            $prefixMatches = $asesores->filter(function (Asesor $asesor) use ($single) {
                $candidate = $this->normalizePersonName($asesor->nombre_asesor);
                return $candidate === $single || str_starts_with($candidate, $single . ' ');
            })->values();

            if ($prefixMatches->count() === 1) {
                return $prefixMatches->first();
            }
        }

        return null;
    }

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

    public function normalizePhone(?string $value): ?string
    {
        if (empty($value)) return null;
        $val = trim($value);
        if (in_array(strtoupper($val), ['S/N', 'NO ESPECIFICADO', 'N/A', 'NA', '-', 'NONE', 'NULL'], true)) {
            return null;
        }
        $noFloat = preg_replace('/\.0+$/', '', $val);
        $digits = preg_replace('/\D/', '', $noFloat);
        return $digits ?: null;
    }

    public function create(array $data): Asesor
    {
        $data['rol_laboral'] = $data['rol_laboral'] ?? 'Gestor de Cobranza';
        $data['id_asesor'] = $this->generateIdAsesor($data['nombre_asesor'], $data['rol_laboral']);
        $data['curp'] = strtoupper($data['curp']);
        $data['cumpleanos'] = $this->cumpleanosFromCurp($data['curp']);
        if (! empty($data['telefono'])) {
            $data['telefono'] = $this->normalizePhone($data['telefono']);
        }

        return Asesor::create($data);
    }

    /**
     * @return array{asesor: Asesor, action: 'created'|'updated'}
     */
    public function upsertFromImport(array $data): array
    {
        $curp = strtoupper($data['curp']);
        $asesor = $this->resolveExistingFromImport($data);
        $nombreImportado = trim((string) $data['nombre_asesor']);
        $nombreFinal = $asesor
            ? $this->pickPreferredName($asesor->nombre_asesor, $nombreImportado)
            : $nombreImportado;

        $payload = [
            'nombre_asesor' => $nombreFinal,
            'cumpleanos' => $this->cumpleanosFromCurp($curp),
            'rol_laboral' => $data['rol_laboral'] ?? 'Gestor de Cobranza',
        ];

        if (array_key_exists('telefono', $data)) {
            $payload['telefono'] = $this->normalizePhone($data['telefono']);
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

    private function pickPreferredName(string $existingName, string $importedName): string
    {
        $existing = trim($existingName);
        $imported = trim($importedName);
        if ($existing === '') {
            return $imported;
        }
        if ($imported === '') {
            return $existing;
        }

        $existingNormalized = $this->normalizePersonName($existing);
        $importedNormalized = $this->normalizePersonName($imported);

        if (
            $existingNormalized !== $importedNormalized
            && $this->nameContainsAllTokens($importedNormalized, $existingNormalized)
            && count($this->tokensFromName($existingNormalized)) >= count($this->tokensFromName($importedNormalized))
        ) {
            return $existing;
        }

        return mb_strlen($imported) > mb_strlen($existing) ? $imported : $existing;
    }

    private function normalizePersonName(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $transliterated !== false ? $transliterated : $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function tokensFromName(string $value): array
    {
        return array_values(array_filter(explode(' ', $value)));
    }

    private function nameContainsAllTokens(string $lookup, string $candidate): bool
    {
        $tokens = $this->tokensFromName($lookup);
        $candidateTokens = $this->tokensFromName($candidate);
        foreach ($tokens as $token) {
            if (! in_array($token, $candidateTokens, true)) {
                return false;
            }
        }

        return $tokens !== [];
    }

    public function resolveRoleForLaboral(?string $rolLaboral): Role
    {
        if (!empty($rolLaboral)) {
            $exact = Role::where('nombre', $rolLaboral)->first();
            if ($exact) {
                return $exact;
            }

            $normalized = mb_strtoupper(trim($rolLaboral), 'UTF-8');
            $candidateName = match (true) {
                str_contains($normalized, 'ADMIN') => 'Administrador',
                str_contains($normalized, 'GEREN') => 'Gerencia',
                str_contains($normalized, 'CONTAB') => 'Contabilidad',
                str_contains($normalized, 'FINANCIERO') => 'Asesor Financiero',
                str_contains($normalized, 'GESTOR') || str_contains($normalized, 'COBRANZA') => 'Gestor de Cobranza',
                default => null,
            };

            if ($candidateName) {
                $role = Role::where('nombre', $candidateName)->first();
                if ($role) {
                    return $role;
                }
            }
        }

        return Role::where('nombre', 'Gestor de Cobranza')->first()
            ?? Role::where('nombre', 'asesor')->first()
            ?? Role::firstOrFail();
    }

    /**
     * Crea usuario de acceso para un asesor con el rol correspondiente a su puesto laboral y contraseña temporal.
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

        $role = $this->resolveRoleForLaboral($asesor->rol_laboral);
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
