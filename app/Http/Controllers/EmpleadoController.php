<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\AhorroEmpleado;
use Illuminate\Http\Request;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmpleadoController extends Controller
{
    public function index()
    {
        return response()->json(Empleado::with(['ahorro', 'user'])->paginate(15));
    }

    public function show($id)
    {
        return response()->json(Empleado::with(['ahorro', 'user'])->findOrFail($id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'puesto' => 'nullable|string',
            'sueldo_base' => 'required|numeric|min:0',
            'porcentaje_ahorro' => 'nullable|numeric|min:0|max:100',
            'percepciones_config' => 'nullable|array',
            'percepciones_config.*.concepto' => 'required_with:percepciones_config|string|max:255',
            'percepciones_config.*.monto' => 'required_with:percepciones_config|numeric|min:0',
            'deducciones_config' => 'nullable|array',
            'deducciones_config.*.concepto' => 'required_with:deducciones_config|string|max:255',
            'deducciones_config.*.monto' => 'required_with:deducciones_config|numeric|min:0',
            'fecha_nacimiento' => 'nullable|date',
            'rfc' => 'nullable|string|max:255',
            'curp' => 'nullable|string|max:255',
            'nss' => 'nullable|string|max:255',
            'banco' => 'nullable|string|max:255',
            'cuenta_bancaria' => 'nullable|string|max:255',
            'despensa' => 'nullable|numeric|min:0',
            'apoyo_transporte' => 'nullable|numeric|min:0',
            'bono_productividad' => 'nullable|numeric|min:0',
            'aportacion_socio' => 'nullable|numeric|min:0',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'nullable|string|min:4',
        ]);

        if (!empty($data['email']) && !empty($data['password'])) {
            $user = User::create([
                'name' => $data['nombre'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $data['user_id'] = $user->id;
        }

        $empleado = Empleado::create($data);
        AhorroEmpleado::create(['empleado_id' => $empleado->id, 'saldo' => 0]);

        return response()->json(['message' => 'Empleado creado', 'data' => $empleado->load('user')], 201);
    }

    public function update(Request $request, $id)
    {
        $empleado = Empleado::findOrFail($id);
        $data = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'puesto' => 'nullable|string',
            'sueldo_base' => 'sometimes|numeric|min:0',
            'porcentaje_ahorro' => 'nullable|numeric|min:0|max:100',
            'percepciones_config' => 'nullable|array',
            'percepciones_config.*.concepto' => 'required_with:percepciones_config|string|max:255',
            'percepciones_config.*.monto' => 'required_with:percepciones_config|numeric|min:0',
            'deducciones_config' => 'nullable|array',
            'deducciones_config.*.concepto' => 'required_with:deducciones_config|string|max:255',
            'deducciones_config.*.monto' => 'required_with:deducciones_config|numeric|min:0',
            'activo' => 'boolean',
            'fecha_nacimiento' => 'nullable|date',
            'rfc' => 'nullable|string|max:255',
            'curp' => 'nullable|string|max:255',
            'nss' => 'nullable|string|max:255',
            'banco' => 'nullable|string|max:255',
            'cuenta_bancaria' => 'nullable|string|max:255',
            'despensa' => 'nullable|numeric|min:0',
            'apoyo_transporte' => 'nullable|numeric|min:0',
            'bono_productividad' => 'nullable|numeric|min:0',
            'aportacion_socio' => 'nullable|numeric|min:0',
            'email' => ['nullable', 'email', Rule::unique('users')->ignore($empleado->user_id)],
            'password' => 'nullable|string|min:4',
        ]);

        if (array_key_exists('email', $data) && !empty($data['email'])) {
            if ($empleado->user_id) {
                $userUpdate = ['email' => $data['email'], 'name' => $data['nombre'] ?? $empleado->nombre];
                if (!empty($data['password'])) {
                    $userUpdate['password'] = Hash::make($data['password']);
                }
                User::where('id', $empleado->user_id)->update($userUpdate);
            } else {
                if (!empty($data['password'])) {
                    $user = User::create([
                        'name' => $data['nombre'] ?? $empleado->nombre,
                        'email' => $data['email'],
                        'password' => Hash::make($data['password']),
                    ]);
                    $data['user_id'] = $user->id;
                }
            }
        }

        $empleado->update($data);
        return response()->json(['message' => 'Actualizado', 'data' => $empleado->load('user')]);
    }

    public function export()
    {
        $empleados = Empleado::orderBy('nombre')->get();
        return response()->json($empleados);
    }

    public function import(Request $request)
    {
        $request->validate([
            'empleados' => 'required|array|min:1',
            'empleados.*.nombre' => 'required|string|max:255',
            'empleados.*.puesto' => 'nullable|string',
            'empleados.*.sueldo_base' => 'nullable|numeric|min:0',
            'empleados.*.fecha_nacimiento' => 'nullable|date',
            'empleados.*.rfc' => 'nullable|string|max:255',
            'empleados.*.curp' => 'nullable|string|max:255',
            'empleados.*.nss' => 'nullable|string|max:255',
            'empleados.*.banco' => 'nullable|string|max:255',
            'empleados.*.cuenta_bancaria' => 'nullable|string|max:255',
            'empleados.*.despensa' => 'nullable|numeric|min:0',
            'empleados.*.apoyo_transporte' => 'nullable|numeric|min:0',
            'empleados.*.bono_productividad' => 'nullable|numeric|min:0',
            'empleados.*.aportacion_socio' => 'nullable|numeric|min:0',
            'empleados.*.activo' => 'nullable|boolean',
            'empleados.*.email' => 'nullable|email',
            'empleados.*.password' => 'nullable|string|min:4',
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($request->input('empleados') as $index => $row) {
            $rowNumber = $index + 2;
            
            // Usamos curp o nombre como llave
            $empleado = null;
            if (!empty($row['curp'])) {
                $empleado = Empleado::where('curp', $row['curp'])->first();
            }
            if (!$empleado && !empty($row['nombre'])) {
                $empleado = Empleado::where('nombre', $row['nombre'])->first();
            }

            if ($empleado) {
                if (!empty($row['email'])) {
                    if ($empleado->user_id) {
                        $userUpdate = ['email' => $row['email'], 'name' => $row['nombre'] ?? $empleado->nombre];
                        if (!empty($row['password'])) {
                            $userUpdate['password'] = Hash::make($row['password']);
                        }
                        User::where('id', $empleado->user_id)->update($userUpdate);
                    } else {
                        if (!empty($row['password'])) {
                            $user = User::create([
                                'name' => $row['nombre'] ?? $empleado->nombre,
                                'email' => $row['email'],
                                'password' => Hash::make($row['password']),
                            ]);
                            $row['user_id'] = $user->id;
                        }
                    }
                }
                $empleado->update($row);
                $updated++;
            } else {
                if (!empty($row['email']) && !empty($row['password'])) {
                    $user = User::create([
                        'name' => $row['nombre'],
                        'email' => $row['email'],
                        'password' => Hash::make($row['password']),
                    ]);
                    $row['user_id'] = $user->id;
                }
                $row['sueldo_base'] = $row['sueldo_base'] ?? 0;
                $newEmp = Empleado::create($row);
                AhorroEmpleado::create(['empleado_id' => $newEmp->id, 'saldo' => 0]);
                $created++;
            }
        }

        return response()->json([
            'message' => "Importación completada.",
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ]);
    }
}
