<?php

namespace App\Console\Commands;

use App\Services\Cfdi\CfdiCalculator;
use App\Services\Cfdi\CfdiValidator;
use App\Services\Cfdi\CfdiXmlBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cfdi:generar
                            {input=cfdi/input.json : Ruta del JSON dentro de storage/app}
                            {--salida=cfdi/cfdi.xml : Ruta del XML de salida dentro de storage/app}')]
#[Description('Genera un CFDI 4.0 (Ingreso) a partir de un JSON y lo valida contra el XSD oficial del SAT')]
class GenerarCfdi extends Command
{
    public function handle(): int
    {
        $inputPath = storage_path('app/'.$this->argument('input'));

        if (! is_file($inputPath)) {
            $this->error("No se encontró el archivo de entrada: {$inputPath}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($inputPath), true);
        if (! is_array($data)) {
            $this->error('El JSON de entrada no es válido.');

            return self::FAILURE;
        }

        // 1) Calcular importes
        $calculado = (new CfdiCalculator)->calcular($data);
        $this->info('Cálculos:');
        $this->line("  SubTotal:        {$calculado['subTotal']}");
        $this->line("  IVA trasladado:  {$calculado['totalTraslados']}");
        $this->line("  TOTAL:           {$calculado['total']}");

        // 2) Construir el XML con DOMDocument
        $xml = (new CfdiXmlBuilder)->construir($calculado);

        // 3) Guardar el archivo .xml
        $salidaPath = storage_path('app/'.$this->option('salida'));
        @mkdir(dirname($salidaPath), 0777, true);
        file_put_contents($salidaPath, $xml);
        $this->info("XML generado en: {$salidaPath}");

        // 4) Validar contra el XSD oficial (espejo local del SAT, sin salir a internet)
        $espejo = storage_path('app/sat/xsd');
        $xsd = $espejo.'/www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd';
        $resultado = (new CfdiValidator($xsd, $espejo))->validar($xml);

        if ($resultado['valido']) {
            $this->info('El XML es VÁLIDO contra el esquema CFDI 4.0.');

            return self::SUCCESS;
        }

        $this->error('El XML NO es válido. Errores encontrados:');
        foreach ($resultado['errores'] as $e) {
            $this->line("  - {$e}");
        }

        return self::FAILURE;
    }
}
