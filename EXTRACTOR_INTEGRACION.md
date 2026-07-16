# Integrar el Extractor de facturas DIAN en un API

Guía para conectar el **extractor** (worker Node/Puppeteer que obtiene los
documentos del catálogo DIAN) a una instalación del API. El API lo consume por
HTTP; el worker es un servicio aparte porque necesita un navegador real (Chrome).

- Código del worker: carpeta **`extractcode/`** del repo.
- Puerto del worker: **3001** (`/api/cufes`, `/api/scrape`, `/api/jobs/...`).
- El API lo alcanza vía la variable **`EXTRACTOR_URL`**.
- Rutas del API para clientes: ver **`API_EXTRACTOR.md`**.

---

## Requisitos

- **Docker** para el worker (trae Chrome + Xvfb + Node; es pesado, no se puede en
  PHP puro).
- **~2 GB de RAM** libres para el worker (Chrome headful bajo Xvfb).
- La carpeta **`extractcode/`** debe estar presente en el servidor (se construye la
  imagen desde ahí).

---

## Elige tu escenario

| Tu API corre en… | Qué hacer |
|------------------|-----------|
| **Docker** | **Editar el `docker-compose.yml` del propio API** (agregar el servicio `cufes-worker`) y reconstruir. |
| **LAMP / bare-metal** | Levantar el worker aparte con **`docker-compose.extractor.yml`**. |

> El `docker-compose.extractor.yml` es **solo para LAMP**. Si el API está en Docker,
> NO se usa un compose aparte: se agrega el servicio al yml que ya tiene el API.

---

## Escenario 1 — API en Docker (editar su propio yml)

### Paso 1 — Traer el código y generar el `.env` del worker

```bash
git pull                        # trae la carpeta extractcode/
cd extractcode
cp .env.example .env            # genera el .env del worker (viene con CHROME_HEADLESS=false, pausas anti-bloqueo, etc.)
cd ..
```

> El `.env` del worker está en `.gitignore`, así que en el servidor **no existe tras
> un clone/pull** — hay que generarlo desde el ejemplo. Trae los valores por defecto
> correctos (headful, delays anti-bloqueo de la DIAN). Ajusta ahí lo que necesites.

### Paso 2 — Agregar el servicio `cufes-worker` al yml del API

Pega el bloque del servicio en el `docker-compose.yml` que ya usa tu API. **Ajusta
2 líneas según tu instalación**, o el worker no quedará en la misma red que
`php_api` / no encontrará el código:

| Instalación | `networks:` del worker | `context:` del build |
|-------------|------------------------|----------------------|
| **Solo API** (compose dentro de la carpeta del API) | `apinet` | `./extractcode` |
| **API + facturador** (compose en `/root`, código en `./apidian`) | `webnet` (o el nombre real que usen los otros servicios) | `./apidian/extractcode` |

> Regla: el `networks:` del worker debe ser el **mismo nombre** que usan los demás
> servicios en ESE yml (míralo en la sección `networks:` al final del archivo), y el
> `context:` debe apuntar a donde esté la carpeta `extractcode/`.
>
> El bloque completo del servicio está en `docker-compose.yml.example` (servicio
> `cufes-worker`).

### Paso 3 — Reconstruir SOLO el worker

Sin recrear los demás contenedores del API:

```bash
docker compose up -d --build cufes-worker
```
(Si tu archivo no es el `docker-compose.yml` por defecto, agrega `-f tu-archivo.yml`.)

### Paso 4 — Verificar

```bash
docker compose exec php_api curl -s http://cufes-worker:3001/api/jobs
# -> {"success":true,"jobs":[]}
```

No hace falta configurar `EXTRACTOR_URL`: el API usa por defecto
`http://cufes-worker:3001` (en `config/services.php`), que resuelve por el nombre
del servicio dentro de la red compartida.

> **¿Antes lo levantaste con `docker-compose.extractor.yml`?** Bájalo primero (usa el
> mismo `container_name` y puerto → conflicto):
> ```bash
> docker compose -f docker-compose.extractor.yml down
> docker ps -a | grep cufes-worker      # no debe aparecer nada
> ```
> y luego haz el Paso 3.

---

## Escenario 2 — API en LAMP / bare-metal

El API corre sin Docker, pero el worker igual va en Docker (necesita Chrome). Se
publica el puerto en `localhost` y el API lo alcanza por ahí.

### Paso 1 — Traer el código y generar el `.env` del worker

```bash
git pull
cd extractcode
cp .env.example .env            # genera el .env del worker (gitignored; no existe tras el pull)
cd ..
```

### Paso 2 — Levantar el worker con el compose aparte

```bash
docker compose -f docker-compose.extractor.yml up -d --build
```
El puerto ya se publica como `127.0.0.1:3001:3001`.

### Paso 3 — Apuntar el API al worker

En el `.env` del API:
```env
EXTRACTOR_URL=http://127.0.0.1:3001
```
```bash
php artisan config:clear
```

### Paso 4 — Verificar

```bash
curl -s http://127.0.0.1:3001/api/jobs
# -> {"success":true,"jobs":[]}
```

---

## Configuración del API (`.env`)

| Variable | Para qué | Valor típico |
|----------|----------|--------------|
| `EXTRACTOR_URL` | Dónde está el worker | `http://cufes-worker:3001` (Docker) · `http://127.0.0.1:3001` (LAMP) |
| `EXTRACTOR_TIMEOUT` | Timeout (s) de las llamadas al worker | `120` |
| `EXTRACTOR_API_KEY` | Solo si el worker exige API key | (vacío) |
| `XML_QUERY_API_KEY` | Protege las rutas API públicas del extractor (header `X-Api-Key`) | *cámbiala en producción* |

Después de cambiar el `.env`:
```bash
php artisan config:clear         # (Docker: docker compose exec php_api php artisan config:clear)
```

---

## Anti-bot de la DIAN: Chrome headful (importante)

La DIAN bloquea Chrome **headless**. El worker debe correr **headful bajo Xvfb**,
controlado por la variable `CHROME_HEADLESS=false`. Esto ya viene cubierto por:

- El `.env` del worker que generas en el Paso 1 (`cp .env.example .env` trae
  `CHROME_HEADLESS=false`).
- Y además el `docker-compose.yml.example` / `docker-compose.extractor.yml` lo
  fijan en `environment`.

Si en los logs ves `headless=true`, es que **falta el `.env`** (no lo generaste) o
la variable no llegó: genera el `.env` y reconstruye el worker.

> Aun con headful, la **IP del servidor** influye: los WAF marcan IPs de datacenter.
> Si el bloqueo persiste, revisa el `_debug-bloqueo.png/.html` que el worker guarda,
> o considera un proxy.

---

## Verificación final

1. Worker arriba:
   ```bash
   docker compose -f docker-compose.extractor.yml ps   # o: docker ps | grep cufes-worker
   ```
2. El API lo alcanza (debe devolver JSON del worker, no un error de Laravel):
   ```bash
   # Docker:
   docker compose exec php_api curl -s http://cufes-worker:3001/api/jobs
   # LAMP:
   curl -s http://127.0.0.1:3001/api/jobs
   ```
3. Desde la UI del API: entra a una empresa → pestaña **Factura de compras** →
   **Probar conexión** → debe dar OK.

---

## Problemas comunes

| Síntoma | Causa | Solución |
|---------|-------|----------|
| `Could not resolve host: cufes-worker` | El worker quedó en una **red distinta** a `php_api` | En el bloque del worker, pon en `networks:` el **mismo nombre** que usan los demás servicios de ese yml (ver tabla del Paso 2) y recréalo. |
| 404 con error de `RouteCollection.php` al probar conexión | `EXTRACTOR_URL` apunta al propio API (`localhost` dentro del contenedor = él mismo) | Usa `http://cufes-worker:3001` (Docker) y `config:clear`. |
| El log dice `headless=true` y la DIAN bloquea | Falta el `.env` del worker (`CHROME_HEADLESS=false`) | Genera el `.env` (`cd extractcode && cp .env.example .env`) y reconstruye el worker. |
| El build de `cufes-worker` falla (no encuentra archivos) | `context:` mal apuntado a la carpeta `extractcode/` | Ajusta `context:` (`./extractcode` solo-API, `./apidian/extractcode` API+facturador). |
| `Chrome terminó… SIGKILL` al final | OOM (poca RAM) | Sube `mem_limit` (2g) del worker. |
| 504 al usar el reporte desde el navegador | Extracción larga en modo síncrono | Ya se usa el modo asíncrono/job; ver `API_EXTRACTOR.md`. |

---

## Referencias

- **`API_EXTRACTOR.md`** — rutas del API para que los clientes consuman el extractor.
- **`docker-compose.yml.example`** — instalación Docker con el worker integrado (bloque `cufes-worker` listo para copiar).
- **`docker-compose.extractor.yml`** — worker aparte, **solo para API en LAMP**.
- **`extractcode/`** — código del worker (Node/Puppeteer), su `Dockerfile` y `.env.example`.
