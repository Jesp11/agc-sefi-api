<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ExcelImportService
{
    public function importarCarteraIndividual(UploadedFile $file): array
    {
        return $this->runScript(
            $file,
            base_path('../scripts/import-cartera-individual/import.py'),
            ['--env', base_path('.env'), '--import-pagos', '--create-asesores']
        );
    }

    public function importarCarteraGrupal(UploadedFile $file): array
    {
        return $this->runScript(
            $file,
            base_path('../scripts/import-cartera-grupal/import.py'),
            ['--env', base_path('.env'), '--import-pagos', '--create-asesores']
        );
    }

    public function importarCarteraMora(UploadedFile $file): array
    {
        return $this->runScript(
            $file,
            base_path('../scripts/reconcile-mora/reconcile.py'),
            ['--env', base_path('.env'), '--import']
        );
    }

    public function importarInversionistas(UploadedFile $file): array
    {
        return $this->runScript(
            $file,
            base_path('../scripts/import-inversionistas/import.py'),
            ['--env', base_path('.env')]
        );
    }

    public function importarFlujoCaja(UploadedFile $file): array
    {
        return $this->runScript(
            $file,
            base_path('../scripts/import-flujo-caja/import.py'),
            ['--env', base_path('.env'), '--create-asesores']
        );
    }

    private function runScript(UploadedFile $file, string $scriptPath, array $extraArgs): array
    {
        $tempPath = $this->storeTempFile($file);

        try {
            $process = new Process([
                'python3',
                $scriptPath,
                $tempPath,
                ...$extraArgs,
            ], base_path('..'));
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return [
                'message' => 'Importación completada',
                'output' => $this->splitOutput($process->getOutput()),
            ];
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function storeTempFile(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'agc-import-');

        if ($tempPath === false) {
            throw new \RuntimeException('No se pudo preparar el archivo temporal para la importación.');
        }

        $targetPath = $tempPath . '.' . $extension;
        @unlink($tempPath);
        $file->move(dirname($targetPath), basename($targetPath));

        return $targetPath;
    }

    private function splitOutput(string $output): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $output) ?: [])));
    }
}
