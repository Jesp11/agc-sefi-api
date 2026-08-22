<?php

namespace Database\Seeders;

use App\Models\AhorroPersonal;
use App\Models\Asesor;
use App\Models\Cliente;
use App\Models\Credito;
use App\Models\Grupo;
use App\Services\AsesorService;
use App\Services\CicloService;
use App\Services\ClienteService;
use App\Services\CreditEngine\Catalogs\GroupRatesCatalog;
use App\Services\CreditEngine\Catalogs\IndividualRatesCatalog;
use App\Services\CreditEngine\Eligibility\GroupEligibilityService;
use App\Services\CreditEngine\Eligibility\IndividualEligibilityService;
use App\Services\CreditEngine\Simulation\AmortizationSimulator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed de cartera para pruebas de cobranza diaria.
 *
 * - Asesor 1: 1 crédito grupal (grupo con 3 integrantes)
 * - Asesor 2: 3 créditos individuales (3 clientes)
 * - Desembolso: 2026-08-03 (lunes)
 * - Primeros pagos escalonados: 10, 11, 12 y 13 de agosto (pago diario)
 * - Montos: mínimo autorizado (individual $3,000 / grupal origen "nuevo" $9,000)
 */
class PrestamosPruebasSeeder extends Seeder
{
    private const FECHA_DESEMBOLSO = '2026-08-03';

    private const PLACEHOLDER = 'NO ESPECIFICADO';

    /** CURPs fijos para re-ejecución idempotente */
    private const CURP_ASESOR_GRUPAL = 'APGJ800101HDFRRN01';

    private const CURP_ASESOR_INDIV = 'APIM800102MDFRRN02';

    private const CURPS_INTEGRANTES = [
        'INPJ900101MDFRRN01',
        'INPJ900102MDFRRN02',
        'INPJ900103MDFRRN03',
    ];

    private const CURPS_INDIVIDUALES = [
        'CLIP900201HDFRRN01',
        'CLIP900202HDFRRN02',
        'CLIP900203HDFRRN03',
    ];

    public function run(): void
    {
        $asesorService = app(AsesorService::class);
        $clienteService = app(ClienteService::class);
        $cicloService = app(CicloService::class);
        $simulator = app(AmortizationSimulator::class);
        $individualEligibility = app(IndividualEligibilityService::class);
        $groupEligibility = app(GroupEligibilityService::class);

        DB::transaction(function () use (
            $asesorService,
            $clienteService,
            $cicloService,
            $simulator,
            $individualEligibility,
            $groupEligibility,
        ) {
            $asesorGrupal = $this->upsertAsesor($asesorService, [
                'nombre_asesor' => 'ASESOR PRUEBA GRUPAL',
                'curp' => self::CURP_ASESOR_GRUPAL,
                'telefono' => '5511110001',
            ]);

            $asesorIndiv = $this->upsertAsesor($asesorService, [
                'nombre_asesor' => 'ASESOR PRUEBA INDIVIDUAL',
                'curp' => self::CURP_ASESOR_INDIV,
                'telefono' => '5511110002',
            ]);

            // --- Asesor 1: grupo + crédito grupal ---
            $integrantes = [];
            foreach ([
                ['nombre' => 'INTEGRANTE PRUEBA UNO', 'curp' => self::CURPS_INTEGRANTES[0], 'tel' => '5522220001'],
                ['nombre' => 'INTEGRANTE PRUEBA DOS', 'curp' => self::CURPS_INTEGRANTES[1], 'tel' => '5522220002'],
                ['nombre' => 'INTEGRANTE PRUEBA TRES', 'curp' => self::CURPS_INTEGRANTES[2], 'tel' => '5522220003'],
            ] as $row) {
                $integrantes[] = $this->upsertCliente($clienteService, [
                    'nombre_completo' => $row['nombre'],
                    'curp' => $row['curp'],
                    'telefono' => $row['tel'],
                    'id_asesor' => $asesorGrupal->id,
                ]);
            }

            $grupo = Grupo::firstOrCreate(
                [
                    'nombre_grupo' => 'GRUPO PRUEBA SEED',
                    'id_asesor' => $asesorGrupal->id,
                ],
                ['es_socio_preferencial' => false]
            );

            $grupo->clientes()->syncWithoutDetaching(
                collect($integrantes)->pluck('id_cliente')->all()
            );

            $montoGrupal = GroupRatesCatalog::getMinimumAmounts()['nuevo']; // 9000
            $primerPagoGrupal = '2026-08-10'; // Lunes
            $this->crearOActualizarCreditoPrueba(
                lookup: [
                    'id_asesor' => $asesorGrupal->id,
                    'tipo_credito' => 'Grupal',
                    'id_grupo' => $grupo->id,
                    'id_cliente' => null,
                ],
                buildPayload: function (int $ciclo) use (
                    $grupo,
                    $asesorGrupal,
                    $integrantes,
                    $montoGrupal,
                    $primerPagoGrupal,
                    $groupEligibility,
                    $simulator
                ) {
                    $tasa = $groupEligibility->determineEligibleRate($ciclo, $montoGrupal, 'nuevo', 0);
                    $plazo = GroupRatesCatalog::getAllowedTermsForCycle($ciclo)[0];
                    $sim = $simulator->simulateGroup($tasa, $montoGrupal, $plazo, count($integrantes));

                    return [
                        'id_cliente' => null,
                        'id_grupo' => $grupo->id,
                        'id_asesor' => $asesorGrupal->id,
                        'fecha_otorgacion' => self::FECHA_DESEMBOLSO,
                        'fecha_primer_pago' => $primerPagoGrupal,
                        'ciclo' => $ciclo,
                        'monto_otorgado' => $sim['monto_total_grupo'],
                        'interes' => $sim['interes_total'],
                        'total' => $sim['total_a_pagar_grupo'],
                        'saldo_pendiente' => $sim['total_a_pagar_grupo'],
                        'plazos' => $sim['plazo_semanas'],
                        'valor_ficha' => $sim['pago_semanal_grupo'],
                        'dias_pago' => $this->diaPago($primerPagoGrupal),
                        'tipo_credito' => 'Grupal',
                        'estado' => 'Activo',
                        'es_personalizado' => false,
                        'es_adicional' => false,
                        'comision_apertura' => 100.00,
                        'tasa_asignada' => $tasa,
                        'porcentaje_interes' => $sim['porcentaje_interes'],
                        'tabla_amortizacion' => $this->buildTabla(
                            $primerPagoGrupal,
                            (int) $sim['plazo_semanas'],
                            (float) $sim['pago_semanal_grupo'],
                            (float) $sim['total_a_pagar_grupo'],
                            'SEED-GRP'
                        ),
                    ];
                },
                cicloService: $cicloService,
            );

            // --- Asesor 2: 3 clientes + 3 créditos individuales ---
            $montoIndiv = IndividualRatesCatalog::getMinimumAmount(); // 3000
            $primerosPagosIndiv = ['2026-08-11', '2026-08-12', '2026-08-13']; // Martes, Miércoles, Jueves

            $clientesIndiv = [
                ['nombre' => 'CLIENTE PRUEBA UNO', 'curp' => self::CURPS_INDIVIDUALES[0], 'tel' => '5533330001'],
                ['nombre' => 'CLIENTE PRUEBA DOS', 'curp' => self::CURPS_INDIVIDUALES[1], 'tel' => '5533330002'],
                ['nombre' => 'CLIENTE PRUEBA TRES', 'curp' => self::CURPS_INDIVIDUALES[2], 'tel' => '5533330003'],
            ];

            foreach ($clientesIndiv as $index => $row) {
                $cliente = $this->upsertCliente($clienteService, [
                    'nombre_completo' => $row['nombre'],
                    'curp' => $row['curp'],
                    'telefono' => $row['tel'],
                    'id_asesor' => $asesorIndiv->id,
                ]);

                $fechaPrimerPago = $primerosPagosIndiv[$index];

                $this->crearOActualizarCreditoPrueba(
                    lookup: [
                        'id_asesor' => $asesorIndiv->id,
                        'tipo_credito' => 'Individual',
                        'id_grupo' => null,
                        'id_cliente' => $cliente->id_cliente,
                    ],
                    buildPayload: function (int $ciclo) use (
                        $cliente,
                        $asesorIndiv,
                        $montoIndiv,
                        $fechaPrimerPago,
                        $index,
                        $individualEligibility,
                        $simulator
                    ) {
                        $tasa = $individualEligibility->determineEligibleRate($ciclo, true, 0);
                        $plazo = IndividualRatesCatalog::getAllowedTermsForAmount($montoIndiv)[0];
                        $sim = $simulator->simulateIndividual($tasa, $montoIndiv, $plazo);

                        return [
                            'id_cliente' => $cliente->id_cliente,
                            'id_grupo' => null,
                            'id_asesor' => $asesorIndiv->id,
                            'fecha_otorgacion' => self::FECHA_DESEMBOLSO,
                            'fecha_primer_pago' => $fechaPrimerPago,
                            'ciclo' => $ciclo,
                            'monto_otorgado' => $sim['monto_solicitado'],
                            'interes' => $sim['interes_total'],
                            'total' => $sim['total_a_pagar'],
                            'saldo_pendiente' => $sim['total_a_pagar'],
                            'plazos' => $sim['plazo_semanas'],
                            'valor_ficha' => $sim['pago_semanal'],
                            'dias_pago' => $this->diaPago($fechaPrimerPago),
                            'tipo_credito' => 'Individual',
                            'estado' => 'Activo',
                            'es_personalizado' => false,
                            'es_adicional' => false,
                            'comision_apertura' => 100.00,
                            'tasa_asignada' => $tasa,
                            'porcentaje_interes' => $sim['porcentaje_interes'],
                            'tabla_amortizacion' => $this->buildTabla(
                                $fechaPrimerPago,
                                (int) $sim['plazo_semanas'],
                                (float) $sim['pago_semanal'],
                                (float) $sim['total_a_pagar'],
                                'SEED-IND-'.($index + 1)
                            ),
                        ];
                    },
                    cicloService: $cicloService,
                );
            }
        });

        $this->command?->info('PrestamosPruebasSeeder: 2 asesores, 1 grupal (3 integrantes) y 3 individuales listos.');
        $this->command?->info('Desembolso: '.self::FECHA_DESEMBOLSO.' | Primeros pagos: 10–13 ago (diario).');
    }

    private function upsertAsesor(AsesorService $service, array $data): Asesor
    {
        $existing = Asesor::where('curp', strtoupper($data['curp']))->first();
        if ($existing) {
            $existing->update([
                'nombre_asesor' => $data['nombre_asesor'],
                'telefono' => $data['telefono'] ?? $existing->telefono,
            ]);
            $asesor = $existing->fresh();
        } else {
            $asesor = $service->create($data);
        }

        AhorroPersonal::firstOrCreate(
            ['asesor_id' => $asesor->id],
            ['saldo' => 0]
        );

        return $asesor;
    }

    private function upsertCliente(ClienteService $service, array $data): Cliente
    {
        $result = $service->upsertFromImport([
            'nombre_completo' => $data['nombre_completo'],
            'curp' => $data['curp'],
            'clave_elector' => self::PLACEHOLDER,
            'telefono' => $data['telefono'],
            'direccion' => self::PLACEHOLDER,
            'entre_calles' => self::PLACEHOLDER,
            'ocupacion' => self::PLACEHOLDER,
            'direccion_trabajo' => self::PLACEHOLDER,
            'telefono_trabajo' => self::PLACEHOLDER,
            'id_asesor' => $data['id_asesor'],
        ]);

        return $result['cliente'];
    }

    /**
     * Crea o actualiza un crédito de prueba sin incrementar el ciclo al re-ejecutar.
     *
     * @param  array{id_asesor:int, tipo_credito:string, id_grupo:?int, id_cliente:?string}  $lookup
     * @param  callable(int): array  $buildPayload
     */
    private function crearOActualizarCreditoPrueba(
        array $lookup,
        callable $buildPayload,
        CicloService $cicloService,
    ): Credito {
        $query = Credito::query()
            ->where('id_asesor', $lookup['id_asesor'])
            ->where('tipo_credito', $lookup['tipo_credito'])
            ->whereDate('fecha_otorgacion', self::FECHA_DESEMBOLSO)
            ->whereIn('estado', ['Activo', 'EnMora']);

        if ($lookup['tipo_credito'] === 'Grupal') {
            $query->where('id_grupo', $lookup['id_grupo']);
        } else {
            $query->where('id_cliente', $lookup['id_cliente']);
        }

        $existente = $query->first();
        $ciclo = $existente
            ? (int) $existente->ciclo
            : $cicloService->calcularCiclo($lookup['id_cliente'], $lookup['id_grupo']);

        $data = $buildPayload($ciclo);

        if ($existente) {
            $existente->update($data);

            return $existente->fresh();
        }

        $credito = Credito::create($data);
        $cicloService->registrarInicio($credito);

        return $credito;
    }

    private function diaPago(string $fecha): string
    {
        $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

        return $dias[Carbon::parse($fecha)->dayOfWeek];
    }

    private function buildTabla(
        string $fechaPrimerPago,
        int $plazos,
        float $valorFicha,
        float $total,
        string $importRef
    ): array {
        $schedule = [];
        $saldo = $total;
        $inicio = Carbon::parse($fechaPrimerPago);

        for ($n = 1; $n <= $plazos; $n++) {
            $saldo = max(0, round($saldo - $valorFicha, 2));
            $schedule[] = [
                'pago_numero' => $n,
                'fecha_sugerida' => $inicio->copy()->addWeeks($n - 1)->toDateString(),
                'monto_pago' => round($valorFicha, 2),
                'saldo_restante' => $saldo,
            ];
        }

        return [
            'import_ref' => $importRef,
            'calendario' => $schedule,
        ];
    }
}
