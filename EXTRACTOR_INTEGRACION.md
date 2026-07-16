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

| Tu API corre en… | Usa el escenario |
|------------------|------------------|
| Docker (instalación nueva) | **A** — integrado en el `docker-compose.yml` |
| Docker (ya instalado, no quieres tocar el compose) | **B** — worker aparte |
| LAMP / bare-metal (sin Docker el API) | **C** — worker en Docker aparte |

---

## Escenario A — API en Docker (integrado)

El `docker-compose.yml.example` ya incluye el servicio `cufes-worker` en la red
`apinet`. Para una instalación nueva:

```bash
# 1. La red externa debe existir (una sola vez)
docker network create apinet

# 2. Levantar todo (API + worker juntos)
docker compose up -d --build
```

No hay que configurar `EXTRACTOR_URL`: el API usa por defecto
`http://cufes-worker:3001` (definido en `config/services.php`), que resuelve por el
nombre del servicio dentro de `apinet`.

Verifica:
```bash
docker compose exec php_api curl -s http://cufes-worker:3001/api/jobs
# -> {"success":true,"jobs":[]}
```

---

## Escenario B — API en Docker, worker APARTE

Para un API ya instalado donde **no quieres modificar** el `docker-compose.yml`
principal. Se usa el compose aparte `docker-compose.extractor.yml` + el script
`extractor-up.sh`, que **detecta solo** la red en la que corre `php_api`
(`proxynet`, `apinet`, o como se llame) y mete el worker ahí.

```bash
git pull                       # trae extractcode/, docker-compose.extractor.yml y extractor-up.sh
chmod +x extractor-up.sh
./extractor-up.sh              # detecta la red de php_api y levanta el worker en ella
```

El script verifica al final que `php_api` alcance a `http://cufes-worker:3001`.

Comandos útiles:
```bash
./extractor-up.sh down         # detener
./extractor-up.sh logs         # ver logs
```

Configura el `.env` del API (o déjalo con el default):
```env
EXTRACTOR_URL=http://cufes-worker:3001
```
```bash
docker compose exec php_api php artisan config:clear
```

---

## Escenario C — API en LAMP / bare-metal

El API corre sin Docker, pero el worker igual va en Docker (necesita Chrome). Se
publica el puerto en `localhost` y el API lo alcanza por ahí.

```bash
git pull
docker compose -f docker-compose.extractor.yml up -d --build
```

En el `docker-compose.extractor.yml` el puerto ya se publica como
`127.0.0.1:3001:3001`. En el `.env` del API:
```env
EXTRACTOR_URL=http://127.0.0.1:3001
```
```bash
php artisan config:clear
```

Verifica:
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

La DIAN bloquea Chrome **headless**. El worker debe correr **headful bajo Xvfb**.
Se controla con la variable del worker `CHROME_HEADLESS=false`:

- En `docker-compose.yml.example` y `docker-compose.extractor.yml` ya viene
  `CHROME_HEADLESS=false`.
- Si el worker toma su config de un `.env` propio (dentro de `extractcode/`),
  asegúrate de que tenga `CHROME_HEADLESS=false`. Ese `.env` está en `.gitignore`,
  así que en el servidor puede no existir: cópialo desde el ejemplo:
  ```bash
  cd extractcode && cp .env.example .env    # trae CHROME_HEADLESS=false por defecto
  ```
  y reconstruye el worker.

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
| `Could not resolve host: cufes-worker` | El API y el worker están en **redes distintas** | Usa `extractor-up.sh` (detecta la red) o pon el worker en la misma red que `php_api`. |
| 404 con error de `RouteCollection.php` al probar conexión | `EXTRACTOR_URL` apunta al propio API (`localhost` dentro del contenedor = él mismo) | Usa `http://cufes-worker:3001` (Docker) y `config:clear`. |
| El log dice `headless=true` y la DIAN bloquea | Falta `CHROME_HEADLESS=false` | Ponla en el compose o en el `.env` del worker y reconstruye. |
| `Chrome terminó… SIGKILL` al final | OOM (poca RAM) | Sube `mem_limit` (2g) del worker. |
| 504 al usar el reporte desde el navegador | Extracción larga en modo síncrono | Ya se usa el modo asíncrono/job; ver `API_EXTRACTOR.md`. |

---

## Referencias

- **`API_EXTRACTOR.md`** — rutas del API para que los clientes consuman el extractor.
- **`docker-compose.yml.example`** — instalación Docker con el worker integrado.
- **`docker-compose.extractor.yml`** + **`extractor-up.sh`** — worker aparte (autodetección de red).
- **`extractcode/`** — código del worker (Node/Puppeteer) y su `Dockerfile`.
