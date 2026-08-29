<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\AhorroEmpleado;
use Illuminate\Http\Request;

class EmpleadoController extends Controller
{
    public function index()
    {
        return response()->json(Empleado::with('ahorro')->paginate(15));
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
        ]);

        $empleado = Empleado::create($data);
        AhorroEmpleado::create(['empleado_id' => $empleado->id, 'saldo' => 0]);

        return response()->json(['message' => 'Empleado creado', 'data' => $empleado], 201);
    }

    public function update(Request $request, $id)
    {
        $empleado = Empleado::findOrFail($id);
        $empleado->update($request->validate([
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
        ]));
        return response()->json(['message' => 'Actualizado', 'data' => $empleado]);
    }
}
