<?php

namespace App\Console\Commands;

use App\Services\ClienteService;
use Illuminate\Console\Command;

class ProcesarClientesCerrados extends Command
{
    protected $signature = 'sefi:procesar-cerrados';
    protected $description = 'Marca clientes como CerradoSinRenovacion tras ventana configurada';

    public function handle(ClienteService $clienteService): int
    {
        $count = $clienteService->procesarCreditosFinalizados();
        $this->info("Procesados {$count} clientes cerrados sin renovación.");
        return self::SUCCESS;
    }
}
