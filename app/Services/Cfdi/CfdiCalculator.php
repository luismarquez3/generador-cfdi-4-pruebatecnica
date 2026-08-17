<?php

namespace App\Services\Cfdi;

/**
 * Responsabilidad única: a partir de los datos crudos del JSON,
 * calcular todos los importes que exige el CFDI 4.0 y devolver el
 * comprobante "enriquecido" listo para construir el XML.
 *
 * No sabe nada de XML ni de validación: solo aritmética fiscal.
 */
class CfdiCalculator
{
    /**
     * @param  array  $data  Estructura decodificada del JSON de entrada.
     * @return array Mismo comprobante + importes calculados.
     */
    public function calcular(array $data): array
    {
        $conceptosCalculados = [];
        $subTotal = 0.0;

        // Los traslados a nivel comprobante se agrupan por
        // impuesto + tipo de factor + tasa. Aquí acumulamos por esa llave.
        $trasladosGlobales = [];

        foreach ($data['conceptos'] as $concepto) {
            $cantidad = (float) $concepto['cantidad'];
            $valorUnitario = (float) $concepto['valorUnitario'];

            // Importe = Cantidad * Valor Unitario, redondeado a 2 decimales (MXN).
            $importe = round($cantidad * $valorUnitario, 2);

            // Base gravable: en este caso, sin descuentos, es igual al importe.
            $base = $importe;

            // La tasa viene como "0.160000".
            $tasa = (float) $concepto['iva'];

            // IVA trasladado del concepto = Base * Tasa, a 2 decimales.
            $ivaImporte = round($base * $tasa, 2);

            $subTotal += $importe;

            // Agrupación para el nodo Impuestos del comprobante.
            $tasaFmt = number_format($tasa, 6, '.', '');   // 0.160000
            $llave = '002|Tasa|'.$tasaFmt;             // 002 = IVA

            if (! isset($trasladosGlobales[$llave])) {
                $trasladosGlobales[$llave] = [
                    'impuesto' => '002',
                    'tipoFactor' => 'Tasa',
                    'tasaOCuota' => $tasaFmt,
                    'base' => 0.0,
                    'importe' => 0.0,
                ];
            }
            $trasladosGlobales[$llave]['base'] += $base;
            $trasladosGlobales[$llave]['importe'] += $ivaImporte;

            // Guardamos el concepto con sus importes ya formateados como cadena.
            $conceptosCalculados[] = array_merge($concepto, [
                'importe' => number_format($importe, 2, '.', ''),
                'base' => number_format($base, 2, '.', ''),
                'ivaImporte' => number_format($ivaImporte, 2, '.', ''),
                'tasaOCuota' => $tasaFmt,
            ]);
        }

        // Total de impuestos trasladados = suma de los IVA de cada grupo.
        $totalTraslados = 0.0;
        foreach ($trasladosGlobales as &$t) {
            $totalTraslados += $t['importe'];
            $t['base'] = number_format($t['base'], 2, '.', '');
            $t['importe'] = number_format($t['importe'], 2, '.', '');
        }
        unset($t);

        // Total del comprobante = SubTotal + impuestos trasladados.
        $total = round($subTotal + $totalTraslados, 2);

        return [
            'comprobante' => $data['comprobante'],
            'emisor' => $data['emisor'],
            'receptor' => $data['receptor'],
            'conceptos' => $conceptosCalculados,
            'subTotal' => number_format($subTotal, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'totalTraslados' => number_format($totalTraslados, 2, '.', ''),
            'trasladosGlobales' => array_values($trasladosGlobales),
        ];
    }
}
