<?php

namespace App\Http\Controllers;

use App\Services\CapitalService;

class CapitalController extends Controller
{
    public function index(CapitalService $capitalService)
    {
        return response()->json($capitalService->capitalPasivo());
    }
}
