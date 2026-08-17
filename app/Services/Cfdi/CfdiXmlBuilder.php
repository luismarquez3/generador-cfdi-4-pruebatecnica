<?php

namespace App\Services\Cfdi;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;

/**
 * Responsabilidad única: construir el XML del CFDI 4.0 nodo por nodo
 * con DOMDocument (no como cadena estática), respetando namespaces,
 * orden de elementos y atributos requeridos por el Anexo 20.
 */
class CfdiXmlBuilder
{
    private const NS_CFDI = 'http://www.sat.gob.mx/cfd/4';

    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param  string  $zonaHoraria  Zona horaria del LugarExpedicion. El Anexo 20
     *                               exige la Fecha en hora LOCAL del lugar de
     *                               expedición; usar UTC (default de Laravel)
     *                               produce comprobantes con fecha adelantada
     *                               que un PAC rechazaría.
     */
    public function __construct(private string $zonaHoraria = 'America/Mexico_City') {}

    public function construir(array $c): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true; // XML indentado y legible

        // ---------- Nodo raíz: cfdi:Comprobante ----------
        // createElementNS liga el prefijo "cfdi" al namespace oficial.
        $comprobante = $dom->createElementNS(self::NS_CFDI, 'cfdi:Comprobante');
        $dom->appendChild($comprobante);

        // Declaramos xsi y la ubicación del esquema (práctica estándar CFDI).
        $comprobante->setAttributeNS(
            self::NS_XSI,
            'xsi:schemaLocation',
            'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd'
        );

        $cmp = $c['comprobante'];
        $comprobante->setAttribute('Version', '4.0');
        $comprobante->setAttribute('Serie', $cmp['serie']);
        $comprobante->setAttribute('Folio', $cmp['folio']);
        $fecha = new DateTimeImmutable('now', new DateTimeZone($this->zonaHoraria));
        $comprobante->setAttribute('Fecha', $fecha->format('Y-m-d\TH:i:s'));

        // El XSD exige Sello, NoCertificado y Certificado aunque no timbremos.
        // Se llenan con placeholders VÁLIDOS (NoCertificado = 20 posiciones).
        $comprobante->setAttribute('Sello', 'SELLO_PLACEHOLDER_SIN_VALOR_FISCAL');
        $comprobante->setAttribute('NoCertificado', '30001000000500003416');
        $comprobante->setAttribute('Certificado', 'CERTIFICADO_PLACEHOLDER_SIN_VALOR_FISCAL');

        $comprobante->setAttribute('FormaPago', $cmp['formaPago']);
        $comprobante->setAttribute('SubTotal', $c['subTotal']);
        $comprobante->setAttribute('Moneda', $cmp['moneda']);
        $comprobante->setAttribute('TipoCambio', $cmp['tipoCambio']);
        $comprobante->setAttribute('Total', $c['total']);
        $comprobante->setAttribute('TipoDeComprobante', $cmp['tipoDeComprobante']);
        $comprobante->setAttribute('Exportacion', $cmp['exportacion']);
        $comprobante->setAttribute('MetodoPago', $cmp['metodoPago']);
        $comprobante->setAttribute('LugarExpedicion', $cmp['lugarExpedicion']);

        // ---------- cfdi:Emisor ----------
        $emisor = $dom->createElementNS(self::NS_CFDI, 'cfdi:Emisor');
        $emisor->setAttribute('Rfc', $c['emisor']['rfc']);
        $emisor->setAttribute('Nombre', $c['emisor']['nombre']);
        $emisor->setAttribute('RegimenFiscal', $c['emisor']['regimenFiscal']);
        $comprobante->appendChild($emisor);

        // ---------- cfdi:Receptor ----------
        $receptor = $dom->createElementNS(self::NS_CFDI, 'cfdi:Receptor');
        $receptor->setAttribute('Rfc', $c['receptor']['rfc']);
        $receptor->setAttribute('Nombre', $c['receptor']['nombre']);
        $receptor->setAttribute('DomicilioFiscalReceptor', $c['receptor']['domicilioFiscalReceptor']);
        $receptor->setAttribute('RegimenFiscalReceptor', $c['receptor']['regimenFiscalReceptor']);
        $receptor->setAttribute('UsoCFDI', $c['receptor']['usoCFDI']);
        $comprobante->appendChild($receptor);

        // ---------- cfdi:Conceptos ----------
        $conceptos = $dom->createElementNS(self::NS_CFDI, 'cfdi:Conceptos');
        $comprobante->appendChild($conceptos);

        foreach ($c['conceptos'] as $con) {
            $concepto = $dom->createElementNS(self::NS_CFDI, 'cfdi:Concepto');
            $concepto->setAttribute('ClaveProdServ', $con['claveProdServ']);
            $concepto->setAttribute('Cantidad', $con['cantidad']);
            $concepto->setAttribute('ClaveUnidad', $con['claveUnidad']);
            $concepto->setAttribute('Unidad', $con['unidad']);
            $concepto->setAttribute('Descripcion', $con['descripcion']);
            $concepto->setAttribute('ValorUnitario', number_format((float) $con['valorUnitario'], 2, '.', ''));
            $concepto->setAttribute('Importe', $con['importe']);
            $concepto->setAttribute('ObjetoImp', $con['objetoImp']);

            // ObjetoImp = 02 (sí objeto de impuesto) => debe llevar nodo Impuestos.
            $impConcepto = $dom->createElementNS(self::NS_CFDI, 'cfdi:Impuestos');
            $trasladosC = $dom->createElementNS(self::NS_CFDI, 'cfdi:Traslados');
            $trasladoC = $dom->createElementNS(self::NS_CFDI, 'cfdi:Traslado');
            $trasladoC->setAttribute('Base', $con['base']);
            $trasladoC->setAttribute('Impuesto', '002');       // IVA
            $trasladoC->setAttribute('TipoFactor', 'Tasa');
            $trasladoC->setAttribute('TasaOCuota', $con['tasaOCuota']);
            $trasladoC->setAttribute('Importe', $con['ivaImporte']);
            $trasladosC->appendChild($trasladoC);
            $impConcepto->appendChild($trasladosC);
            $concepto->appendChild($impConcepto);

            $conceptos->appendChild($concepto);
        }

        // ---------- cfdi:Impuestos (nivel comprobante) ----------
        // Va DESPUÉS de Conceptos. Agrupa los traslados por impuesto/tasa.
        $impuestos = $dom->createElementNS(self::NS_CFDI, 'cfdi:Impuestos');
        $impuestos->setAttribute('TotalImpuestosTrasladados', $c['totalTraslados']);

        $trasladosG = $dom->createElementNS(self::NS_CFDI, 'cfdi:Traslados');
        foreach ($c['trasladosGlobales'] as $t) {
            $tg = $dom->createElementNS(self::NS_CFDI, 'cfdi:Traslado');
            // En CFDI 4.0 el Traslado global TAMBIÉN lleva Base (novedad vs 3.3).
            $tg->setAttribute('Base', $t['base']);
            $tg->setAttribute('Impuesto', $t['impuesto']);
            $tg->setAttribute('TipoFactor', $t['tipoFactor']);
            $tg->setAttribute('TasaOCuota', $t['tasaOCuota']);
            $tg->setAttribute('Importe', $t['importe']);
            $trasladosG->appendChild($tg);
        }
        $impuestos->appendChild($trasladosG);
        $comprobante->appendChild($impuestos);

        return $dom->saveXML();
    }
}
