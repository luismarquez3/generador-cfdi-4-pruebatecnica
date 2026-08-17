<?php

namespace App\Services\Cfdi;

use DOMDocument;

/**
 * Responsabilidad única: validar un XML contra el XSD oficial de
 * CFDI 4.0 y devolver, si falla, la lista de errores encontrados.
 */
class CfdiValidator
{
    /**
     * @param  string  $xsdPath  Ruta local al cfdv40.xsd.
     * @param  string|null  $espejoPath  Raíz del espejo local de los XSD del SAT.
     *                                   cfdv40.xsd importa catCFDI.xsd y tdCFDI.xsd
     *                                   por URL ABSOLUTA (no relativa), así que sin
     *                                   este espejo libxml saldría a internet a
     *                                   resolverlas. Con la raíz indicada, la
     *                                   validación funciona sin conexión.
     */
    public function __construct(
        private string $xsdPath,
        private ?string $espejoPath = null,
    ) {}

    /**
     * @return array{valido: bool, errores: array<int, string>}
     */
    public function validar(string $xml): array
    {
        if (! is_file($this->xsdPath)) {
            return [
                'valido' => false,
                'errores' => ["No se encontró el esquema XSD en: {$this->xsdPath}"],
            ];
        }

        // Redirigimos los errores de libxml a memoria en lugar de a la salida,
        // para poder listarlos nosotros mismos.
        $previo = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $usaEspejo = $this->espejoPath !== null;

        if ($usaEspejo) {
            $this->registrarResolutorDeEsquemas($this->espejoPath);
        }

        // El @ silencia el warning de ext/dom cuando el esquema no carga
        // (se convertiría en ErrorException bajo el handler de Laravel);
        // el detalle real ya queda en libxml_get_errors().
        $valido = @$dom->schemaValidate($this->xsdPath);

        if ($usaEspejo) {
            libxml_set_external_entity_loader(null);
        }

        $errores = [];
        foreach (libxml_get_errors() as $error) {
            $errores[] = sprintf('[línea %d] %s', $error->line, trim($error->message));
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        return ['valido' => $valido, 'errores' => $errores];
    }

    /**
     * Resuelve las URLs http(s) que importa el XSD del SAT contra el espejo
     * local, para validar sin salir a internet y sin modificar los archivos
     * oficiales (quedan idénticos a los publicados por el SAT).
     */
    private function registrarResolutorDeEsquemas(string $espejoPath): void
    {
        $raiz = rtrim($espejoPath, '/');

        libxml_set_external_entity_loader(
            static function (?string $public, string $systemId, array $context) use ($raiz) {
                $ruta = $systemId;

                if (preg_match('#^https?://(.+)$#', $systemId, $coincidencias) === 1) {
                    $ruta = $raiz.'/'.$coincidencias[1];
                } elseif (str_starts_with($systemId, 'file://')) {
                    $ruta = substr($systemId, 7);
                }

                return is_file($ruta) ? fopen($ruta, 'r') : null;
            }
        );
    }
}
