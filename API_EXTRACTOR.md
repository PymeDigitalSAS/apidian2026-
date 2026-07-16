# API del Extractor DIAN

Rutas del API (Laravel) para extraer facturas de la DIAN desde sistemas externos.
El API orquesta el worker Node (navegador) que obtiene los documentos del catálogo,
y luego consulta/procesa los XML. El cliente **solo cambia la URL base**.

> Las respuestas replican la estructura del extractor (`parseUblXml`): claves como
> `TipoDocumento`, `CUFE`, `Sub Total`, `TaxBreakdown`, `LineItems`, etc.

---

## Método recomendado: token de empresa

La forma recomendada es autenticarse con el **token de la empresa** (el mismo
`api_token` que usa la empresa para los eventos RADIAN). Así **no se envía ningún
certificado** en la petición: el API usa el **certificado que la empresa ya tiene
configurado** para consultar los XML en la DIAN. La respuesta son los **datos ya
extraídos y procesados** (no solo los CUFEs).

- **Ventaja**: el cliente no expone su `.p12` en cada llamada; reutiliza la
  configuración de su empresa.
- **Requisito**: la empresa debe existir y tener **software** y **certificado**
  configurados (lo mismo que ya necesita para emitir/enviar documentos).

---

## Autenticación

### A) Token de empresa (Bearer) — recomendado

```
Authorization: Bearer <api_token de la empresa>
```

Aplica a: `POST /api/extractor/company`, `GET /api/extractor/company/jobs/{id}`,
`POST /api/extractor/company/stream` y `POST /api/ubl2.1/xml/document/{cufe}`.

### B) API Key global (X-Api-Key)

```
X-Api-Key: <XML_QUERY_API_KEY>
```

Aplica a las rutas sin empresa/certificado (`/api/scrape`, `/api/cufes`). El valor
es la variable `XML_QUERY_API_KEY` del `.env`. Si falta o no coincide: `401`.

**Base URL** (ejemplo): `https://api.tu-dominio.com`

---

## Resumen de rutas

| Método | Ruta | Auth | Devuelve |
|--------|------|------|----------|
| `POST` | `/api/extractor/company/stream` | Bearer empresa | **JSON `{log, data}` al terminar** — un solo endpoint |
| `POST` | `/api/extractor/company` | Bearer empresa | jobId (async, alternativa) |
| `GET`  | `/api/extractor/company/jobs/{id}` | Bearer empresa | Avance + datos procesados |
| `POST` | `/api/ubl2.1/xml/document/{cufe}` | Bearer empresa | XML (base64) de UN documento por CUFE (endpoint existente) |
| `POST` | `/api/scrape` | X-Api-Key | Documentos completos `{log, data}` |
| `GET`  | `/api/jobs/{id}` | X-Api-Key | Estado de un job del worker |
| `POST` | `/api/cufes` | X-Api-Key | Solo la lista de CUFEs |

> **Dos formas de consumir el mismo flujo con token de empresa:**
> - **`/stream`** (simple, recomendado para clientes): **un solo endpoint**; se
>   llama una vez y devuelve el JSON `{ log, data }` cuando termina el proceso.
> - **async + polling** (`/company` + `/company/jobs/{id}`): para procesos largos o
>   detrás de un proxy con timeout corto; se llama e interroga por `jobId`.

> **Campo `tipo` (`Received` | `Sent`)** — aplica a todos los flujos de extracción:
> - **`Received`** (recibidos): documentos que le emitieron a la empresa. Se valida
>   que la empresa sea el adquiriente; solo estos se pueden **registrar en Eventos
>   RADIAN**.
> - **`Sent`** (enviados): documentos que la empresa emitió. Son **solo para el
>   reporte** (no aplican eventos RADIAN); no se valida el adquiriente, así que los
>   documentos válidos vuelven con estado `ok`.

---

## 1. `POST /api/extractor/company/stream` — un solo endpoint, JSON `{ log, data }`

**Una sola llamada** que hace todo el proceso (obtiene los CUFEs con el navegador y
consulta cada XML con el certificado de la empresa) y devuelve el JSON
`{ log, data }` **cuando termina**. La respuesta llega completa al final (no es en
vivo), como el extractor original.

### Autenticación
`Authorization: Bearer <api_token de la empresa>`

### Petición

```json
{
  "tipo": "Received",
  "fechaDesde": "2025-08-01",
  "fechaHasta": "2025-08-31",
  "url": "https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."
}
```

### Respuesta (`application/json`)

Al terminar el proceso devuelve un JSON con `log` (las líneas del proceso) y `data`
(los documentos procesados):

```json
{
  "log": [
    "✅ Extracción iniciada",
    "🟡 Obteniendo CUFEs del catálogo DIAN…",
    "📄 Página 1",
    "🟢 CUFEs obtenidos: 9. Consultando la DIAN por cada uno…",
    "⬇️ Procesado 1/9 [ok]",
    "⬇️ Procesado 9/9 [ok]",
    "✅ Proceso terminado"
  ],
  "data": [
    { "TipoDocumento": "Invoice", "CUFE": "...", "Sub Total": 99495.8, "TaxBreakdown": [...], "LineItems": [...] }
  ]
}
```

### Cómo consumirlo

Una sola llamada, se lee como cualquier JSON:

```js
const res = await fetch('https://api.tu-dominio.com/api/extractor/company/stream', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer TOKEN_EMPRESA', 'Content-Type': 'application/json' },
  body: JSON.stringify({ tipo: 'Received', fechaDesde: '2025-08-01', fechaHasta: '2025-08-31', url: '...' }),
});
const { log, data } = await res.json();   // JSON final { log, data }
console.log(data);                        // documentos procesados
```

```bash
curl -X POST https://api.tu-dominio.com/api/extractor/company/stream \
  -H "Authorization: Bearer TOKEN_EMPRESA" -H "Content-Type: application/json" \
  -d '{"tipo":"Received","fechaDesde":"2025-08-01","fechaHasta":"2025-08-31","url":"https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."}'
```

> **Nota**: el proceso puede tardar minutos. Si tu API está detrás de un proxy con
> límite de tiempo (p. ej. nginx a 60s), para rangos grandes usa el modo **async +
> polling** (sección 2), que no depende de mantener la conexión abierta.

---

## 2. `POST /api/extractor/company` — iniciar extracción (token de empresa)

Arranca el proceso con el certificado de la empresa autenticada. Es **asíncrono**:
devuelve un `jobId` al instante (el proceso tarda minutos) y el resultado se
consulta con la ruta de estado.

### Autenticación
`Authorization: Bearer <api_token de la empresa>`

### Petición

```json
{
  "tipo": "Received",
  "fechaDesde": "2025-08-01",
  "fechaHasta": "2025-08-31",
  "url": "https://catalogo-vpfe.dian.gov.co/User/AuthToken?pk=...&rk=...&token=...",
  "excluirEstados": null
}
```

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|:-----------:|-------------|
| `tipo` | `Sent` \| `Received` | ✅ | Documentos enviados o recibidos. |
| `fechaDesde` | `YYYY-MM-DD` | ✅ | Inicio del rango. |
| `fechaHasta` | `YYYY-MM-DD` | ✅ | Fin del rango. |
| `url` | string (URL) | ✅ | Enlace `User/AuthToken` de DIAN. **Debe ser del mismo ambiente** (Producción / Habilitación) que la empresa. |
| `excluirEstados` | string | ❌ | Estados RADIAN a excluir (opcional). |

### Respuesta (`202`)

```json
{
  "success": true,
  "jobId": "3f2a...-...-...",
  "statusUrl": "https://api.tu-dominio.com/api/extractor/company/jobs/3f2a...",
  "message": "Extracción iniciada. Consulte statusUrl para el avance y el resultado."
}
```

Errores posibles: `422` si la empresa está inactiva o no tiene software/certificado.

---

## 3. `GET /api/extractor/company/jobs/{id}` — avance y resultado

Se consulta por **polling** hasta `status: done` (o `error`). En cada consulta el
proceso avanza: primero obtiene los CUFEs (navegador), luego consulta y procesa los
documentos con el certificado de la empresa **por lotes** (por eso conviene
consultar cada pocos segundos; ninguna consulta tarda mucho).

### Autenticación
`Authorization: Bearer <api_token de la empresa>`

### Mientras corre

```json
{
  "success": true,
  "status": "running",
  "stage": "getting_cufes",
  "total": 0,
  "processed": 0,
  "log": [ "✅ Extracción iniciada", "🟡 Obteniendo CUFEs del catálogo DIAN…" ]
}
```

`stage` refleja el flujo en vivo: `getting_cufes` → `enriching` → `done`.

```json
{
  "success": true,
  "status": "running",
  "stage": "enriching",
  "total": 9,
  "processed": 5,
  "log": [ "...", "🟢 CUFEs obtenidos: 9. Consultando la DIAN por cada uno…", "⬇️ Procesados 5/9 documento(s)…" ]
}
```

### Al terminar (`status: done`)

```json
{
  "success": true,
  "status": "done",
  "stage": "done",
  "total": 9,
  "processed": 9,
  "log": [ "...", "⬇️ Procesados 9/9 documento(s)…", "✅ Proceso terminado" ],
  "data": [
    {
      "TipoDocumento": "Invoice",
      "TipoBusqueda": "Received",
      "Numero de Factura": "FEBC24",
      "CUFE": "41a77209dcc997ca657d4f858f09c93c41dcc7149ff25c525190f3a5d0d4af7b72d7e3179780096c7b9249a52d5c5136",
      "Fecha de Creación": "2025-08-05",
      "Fecha de Vencimiento": "2025-08-05",
      "Moneda": "COP",
      "NIT del Emisor": "901846280",
      "Razon Social del Emisor": "DIGITAL BÚHO SAS",
      "NIT del Receptor": "901827899",
      "Razon Social del Receptor": "CASA ESPIRITU S A S",
      "Metodo de Pago": "10",
      "Sub Total": 99495.8,
      "Impuestos Cabecera": 18904.2,
      "Retenciones": 0,
      "Total a Pagar": 118400,
      "TaxBreakdown": [
        { "code": "01", "name": "IVA", "percent": 19, "taxable_amount": 99495.8, "amount": 18904.2 }
      ],
      "LineItems": [
        {
          "Nombre del Producto": "Certificados Firma Digital - DIAN Colombia - Anual",
          "Referencia": "CFC-A",
          "Unidad de Medida": "94",
          "Cantidad": 1,
          "Precio Unitario": 143273.95,
          "Total": 99495.8,
          "taxes": [
            { "code": "01", "name": "IVA", "percent": 19, "taxable_amount": 99495.8, "amount": 18904.2 }
          ],
          "total_tax_line": 18904.2
        }
      ]
    }
  ]
}
```

### Estados

| `status` | Significado |
|----------|-------------|
| `running` | En proceso (ver `stage` y `log`). |
| `done` | Terminado; `data` trae los documentos procesados. |
| `error` | Falló; ver `message` y `log`. |
| `404` | Job expirado, inexistente o de otra empresa. |

> Recomendación de polling: cada **3–5 segundos**. Los jobs viven ~2 horas en
> caché, luego se limpian.

---

## 4. `POST /api/ubl2.1/xml/document/{cufe}` — XML de un documento por CUFE

Endpoint **existente** del API. Consulta en la DIAN el XML de un documento por su
**CUFE** (va en la URL) usando el certificado de la empresa autenticada. Devuelve la
respuesta de la DIAN con el **XML en base64** (`XmlBytesBase64`). Es el que usa el
tab **Consulta por CUFE** de la interfaz.

### Autenticación
`Authorization: Bearer <api_token de la empresa>`

### Petición

El CUFE va en la URL. En el body, `is_event: true` para consultar por el endpoint
de eventos (documentos recibidos):

```
POST /api/ubl2.1/xml/document/41a77209dcc997ca657d4f858...
```
```json
{ "is_event": true }
```

### Respuesta

```json
{
  "success": true,
  "message": "Consulta generada con éxito",
  "ResponseDian": {
    "Envelope": { "Body": { "GetXmlByDocumentKeyResponse": { "GetXmlByDocumentKeyResult": {
      "Code": "100",
      "XmlBytesBase64": "PD94bWwgdmVyc2lvbj0iMS4w..."   // <- el XML del documento, en base64
    } } } }
  },
  "certificate_days_left": 123
}
```

El XML va en `ResponseDian → Envelope → Body → GetXmlByDocumentKeyResponse →
GetXmlByDocumentKeyResult → XmlBytesBase64`. Decodifícalo (base64) para obtener el
XML UBL. Si el documento viene envuelto en un `AttachedDocument`, la factura real
está embebida en un `cbc:Description`.

### Ejemplo (cURL)

```bash
curl -X POST https://api.tu-dominio.com/api/ubl2.1/xml/document/41a77209... \
  -H "Authorization: Bearer TOKEN_EMPRESA" -H "Content-Type: application/json" \
  -d '{"is_event":true}'
```

### Registrar en Eventos RADIAN (opcional, tras la consulta)

Solo para documentos **recibidos** por esta empresa (el backend valida el
adquiriente y rechaza si no aplica):

```bash
curl -X POST https://api.tu-dominio.com/api/load-document-by-cufe \
  -H "Authorization: Bearer TOKEN_EMPRESA" -H "Content-Type: application/json" \
  -d '{"cufe":"41a77209...","save":true}'
```

---

## 5. `POST /api/scrape` — extracción por token sin empresa (X-Api-Key)

Alternativa **sin empresa ni certificado**: con el enlace `User/AuthToken`
autentica la sesión del navegador y **descarga** los documentos del listado. Útil
si no se quiere/tiene una empresa configurada.

> Es más lento (descarga ZIP por documento). Para rangos con varios documentos usa
> `async: true` + polling de `GET /api/jobs/{id}`.

### Autenticación
`X-Api-Key: <XML_QUERY_API_KEY>`

### Petición

```json
{
  "tipo": "Sent",
  "fechaDesde": "2025-08-01",
  "fechaHasta": "2025-08-01",
  "url": "https://catalogo-vpfe.dian.gov.co/User/AuthToken?...",
  "async": false
}
```

### Respuesta síncrona (`async: false`)

```json
{
  "partial": false,
  "log": [ "✅ Iniciando proceso", "⬇️ Descargando archivo 1...", "✅ Proceso terminado" ],
  "data": [ { "TipoDocumento": "Invoice", "CUFE": "...", "TaxBreakdown": [...], "LineItems": [...] } ]
}
```

### Modo asíncrono (`async: true`)

```json
POST /api/scrape   { ..., "async": true }
→ { "success": true, "jobId": "abc-123", "statusUrl": "/api/jobs/abc-123" }

GET /api/jobs/abc-123   (X-Api-Key)
→ running: { "status": "running", "log": [...], "data": null }
→ done:    { "status": "done", "log": [...], "data": [ {...} ] }
```

---

## 6. `POST /api/cufes` — solo la lista de CUFEs (X-Api-Key)

Rápido: obtiene únicamente los CUFEs del listado (no descarga XMLs ni los procesa).

### Autenticación
`X-Api-Key: <XML_QUERY_API_KEY>`

### Petición

```json
{
  "tipo": "Received",
  "fechaDesde": "2025-08-01",
  "fechaHasta": "2025-08-31",
  "url": "https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."
}
```

### Respuesta

```json
{
  "success": true,
  "total": 5,
  "message": "Se obtuvieron 5 CUFE(s)",
  "data": [
    { "cufe": "41a77209...", "Numero de Factura": "FEBC24", "NIT del Emisor": "901846280", "...": "..." }
  ]
}
```

---

## Códigos de error comunes

| Código | Significado |
|--------|-------------|
| `401` | Falta/incorrecto el `Authorization: Bearer` (empresa) o el `X-Api-Key`. |
| `422` | Validación fallida, empresa inactiva o sin software/certificado, o `EXTRACTOR_URL` no configurada. |
| `404` | Job expirado, inexistente o de otra empresa. |
| `502` | No se pudo conectar al worker / error consultando el extractor. |
| `500` | Error inesperado. |

---

## Notas de configuración

- **`EXTRACTOR_URL`** (en el `.env` del API): apunta al worker.
  - API en Docker: `http://cufes-worker:3001`
  - API en LAMP/bare-metal: `http://127.0.0.1:3001`
- **`XML_QUERY_API_KEY`**: clave del header `X-Api-Key` (rutas sin empresa).
- **Ambiente del token**: el enlace `User/AuthToken` debe ser del mismo ambiente
  (Producción = `catalogo-vpfe.dian.gov.co`, Habilitación = `catalogo-vpfe-hab.dian.gov.co`)
  que la empresa / los documentos a consultar.

---

## Ejemplos rápidos (cURL)

```bash
# === RECOMENDADO: token de empresa (usa el certificado de la empresa) ===

# 1) Iniciar la extracción -> devuelve jobId
curl -X POST https://api.tu-dominio.com/api/extractor/company \
  -H "Authorization: Bearer EL_API_TOKEN_DE_LA_EMPRESA" \
  -H "Content-Type: application/json" \
  -d '{"tipo":"Received","fechaDesde":"2025-08-01","fechaHasta":"2025-08-31","url":"https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."}'

# 2) Consultar avance/resultado (polling cada 3-5s hasta status "done")
curl https://api.tu-dominio.com/api/extractor/company/jobs/EL_JOB_ID \
  -H "Authorization: Bearer EL_API_TOKEN_DE_LA_EMPRESA"


# === Sin empresa (X-Api-Key) ===

# Extracción por token (async recomendado)
curl -X POST https://api.tu-dominio.com/api/scrape \
  -H "X-Api-Key: TU_KEY" -H "Content-Type: application/json" \
  -d '{"tipo":"Sent","fechaDesde":"2025-08-01","fechaHasta":"2025-08-01","url":"https://catalogo-vpfe.dian.gov.co/User/AuthToken?...","async":true}'
curl https://api.tu-dominio.com/api/jobs/EL_JOB_ID -H "X-Api-Key: TU_KEY"

# Solo CUFEs
curl -X POST https://api.tu-dominio.com/api/cufes \
  -H "X-Api-Key: TU_KEY" -H "Content-Type: application/json" \
  -d '{"tipo":"Received","fechaDesde":"2025-08-01","fechaHasta":"2025-08-31","url":"https://catalogo-vpfe.dian.gov.co/User/AuthToken?..."}'
```
