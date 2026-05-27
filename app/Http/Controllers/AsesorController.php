<?php

namespace App\Http\Controllers;

use App\Models\Asesor;
use App\Http\Requests\StoreAsesorRequest;
use App\Http\Requests\UpdateAsesorRequest;
use Illuminate\Http\Request;

class AsesorController extends Controller
{
    public function index()
    {
        $asesores = Asesor::paginate(10);
        return response()->json($asesores);
    }

    public function store(StoreAsesorRequest $request)
    {
        $data = $request->validated();

        // Generar id_asesor: AF + iniciales del nombre + número secuencial
        $nombre = $data['nombre_asesor'];
        $words = explode(' ', strtoupper(trim($nombre)));
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= substr($word, 0, 1);
            }
        }
        $prefix = 'AF' . $initials;

        $lastAsesor = Asesor::where('id_asesor', 'like', $prefix . '%')
            ->orderBy('id_asesor', 'desc')
            ->first();

        if ($lastAsesor) {
            $number = (int) substr($lastAsesor->id_asesor, strlen($prefix));
            $newNumber = $number + 1;
        } else {
            $newNumber = 1;
        }

        $data['id_asesor'] = $prefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);

        // Extraer cumpleaños de CURP (posiciones 4-9: AAMMDD)
        $curp = strtoupper($data['curp']);
        $year = substr($curp, 4, 2);
        $month = substr($curp, 6, 2);
        $day = substr($curp, 8, 2);
        $currentYear = (int) date('y');
        $fullYear = ((int)$year > $currentYear) ? '19' . $year : '20' . $year;
        $data['cumpleanos'] = $fullYear . '-' . $month . '-' . $day;

        $asesor = Asesor::create($data);
        return response()->json([
            'message' => 'Asesor creado exitosamente',
            'data' => $asesor
        ], 201);
    }

    public function show($id)
    {
        $asesor = Asesor::with('creditos')->findOrFail($id);
        return response()->json($asesor);
    }

    public function update(UpdateAsesorRequest $request, $id)
    {
        $asesor = Asesor::findOrFail($id);
        $asesor->update($request->validated());
        return response()->json([
            'message' => 'Asesor actualizado exitosamente',
            'data' => $asesor
        ]);
    }

    public function destroy($id)
    {
        $asesor = Asesor::findOrFail($id);
        $asesor->delete();
        return response()->json([
            'message' => 'Asesor eliminado exitosamente'
        ]);
    }
}
