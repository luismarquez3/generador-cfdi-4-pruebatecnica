# Prueba técnica — Generación y validación de CFDI 4.0

Genera programáticamente un CFDI 4.0 de tipo Ingreso (`TipoDeComprobante="I"`) a partir de
un JSON, lo escribe a disco como `.xml` y lo valida contra el XSD oficial del SAT.

No se realiza timbrado, no se usa PAC y no se emplean archivos `.cer` / `.key`.
`Sello`, `NoCertificado` y `Certificado` se llenan con placeholders válidos según el
esquema, sin valor fiscal.

## Requisitos

- PHP 8.3+ con las extensiones `dom`, `libxml` y `xml` (vienen por defecto)
- Composer

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
```

No se necesita base de datos: todo el flujo corre en memoria y sobre el sistema de archivos.

## Uso

```bash
php artisan cfdi:generar
```

Salida esperada:

```
Cálculos:
  SubTotal:        8026.46
  IVA trasladado:  1284.23
  TOTAL:           9310.69
XML generado en: storage/app/cfdi/cfdi.xml
El XML es VÁLIDO contra el esquema CFDI 4.0.
```

El comando devuelve exit code `0` si el XML es válido y `1` si no lo es, de modo que se
puede encadenar en CI.

### Opciones

| Argumento / opción | Default | Descripción |
| --- | --- | --- |
| `input` | `cfdi/input.json` | Ruta del JSON de entrada, relativa a `storage/app` |
| `--salida` | `cfdi/cfdi.xml` | Ruta del XML de salida, relativa a `storage/app` |

```bash
php artisan cfdi:generar otra/factura.json --salida=otra/factura.xml
```

## Validación contra el XSD

Los tres esquemas oficiales del SAT están incluidos en el repositorio bajo
`storage/app/sat/xsd/`, tal cual los publica el SAT (sin modificar):

| Archivo | Origen |
| --- | --- |
| `cfdv40.xsd` | http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd |
| `catCFDI.xsd` | http://www.sat.gob.mx/sitio_internet/cfd/catalogos/catCFDI.xsd |
| `tdCFDI.xsd` | http://www.sat.gob.mx/sitio_internet/cfd/tipoDatos/tdCFDI/tdCFDI.xsd |

`cfdv40.xsd` importa los otros dos por **URL absoluta**, no relativa. Para no depender de
internet al validar, `CfdiValidator` registra un `libxml_set_external_entity_loader` que
resuelve esas URLs contra el espejo local. Así los XSD quedan byte a byte idénticos a los
oficiales (verificables) y la validación funciona sin conexión.

Si necesitas regenerar el espejo:

```bash
cd storage/app/sat/xsd
wget -x http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd
wget -x http://www.sat.gob.mx/sitio_internet/cfd/catalogos/catCFDI.xsd
wget -x http://www.sat.gob.mx/sitio_internet/cfd/tipoDatos/tdCFDI/tdCFDI.xsd
```

Cuando la validación falla, el comando imprime cada error de libxml con su número de línea.

## Estructura

El flujo está partido en tres clases con una responsabilidad cada una, todas en
`app/Services/Cfdi/`:

| Clase | Responsabilidad |
| --- | --- |
| `CfdiCalculator` | Aritmética fiscal. Recibe el array del JSON y devuelve el mismo comprobante con importes, bases, IVA por concepto, subtotal, total de traslados y total. Función pura: no sabe de XML ni de I/O. |
| `CfdiXmlBuilder` | Construye el XML nodo por nodo con `DOMDocument` — namespaces, orden de elementos y atributos del Anexo 20. |
| `CfdiValidator` | Valida el XML resultante contra el XSD y devuelve `['valido' => bool, 'errores' => string[]]`. |

`app/Console/Commands/GenerarCfdi.php` es el único punto que las orquesta y toca el
sistema de archivos.

## Cálculos

Por concepto:

- `Importe = Cantidad × ValorUnitario`, redondeado a 2 decimales
- `Base = Importe` (no hay descuentos en la entrada de esta prueba)
- `Importe del traslado = Base × TasaOCuota`, redondeado a 2 decimales

A nivel comprobante:

- `SubTotal` = suma de los `Importe` de los conceptos
- `TotalImpuestosTrasladados` = suma de los traslados, agrupados por
  `Impuesto + TipoFactor + TasaOCuota`
- `Total` = `SubTotal + TotalImpuestosTrasladados`

Los importes se emiten como cadena con `number_format(..., 2, '.', '')` y las tasas con 6
decimales, porque el XSD valida el formato literal del atributo.

La agrupación de traslados soporta varias tasas: si dos conceptos traen tasas distintas,
se emite un `cfdi:Traslado` por cada combinación única a nivel comprobante.

## Fecha

El Anexo 20 exige `Fecha` en hora **local del `LugarExpedicion`**, no en UTC.
`CfdiXmlBuilder` recibe la zona horaria por constructor, con default
`America/Mexico_City`:

```php
new CfdiXmlBuilder('America/Tijuana');
```

## Alcance y limitaciones conocidas

Delimitado a lo que pide la prueba:

- Solo traslados de IVA (`Impuesto="002"`, `TipoFactor="Tasa"`). No hay IEPS, retenciones,
  `TipoFactor="Cuota"` ni `Exento`. Para soportarlos, el JSON tendría que traer los
  impuestos como lista por concepto en vez del campo escalar `iva`; la llave de agrupación
  del calculator ya está preparada para ello.
- No se aplica `Descuento` a nivel concepto ni comprobante.
- Se asume `ObjetoImp="02"` en todos los conceptos, por lo que siempre se emite el nodo
  `cfdi:Impuestos` del concepto.
- El JSON de entrada no se valida contra un esquema propio: el contrato de entrada es
  implícito. Solo se verifica que sea JSON decodificable.
