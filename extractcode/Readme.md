# Extractor de documentos DIAN V2

Servicio web (Node + Express + Puppeteer) que automatiza la descarga de documentos
electrónicos desde el portal de la DIAN y arma un Excel con la información. Expone
una interfaz web (`http://localhost:3000`) y una API REST para integraciones.

Hay **dos modos de extracción**:

| Modo | Cómo autentica | Windows | Ubuntu local | VPS (nativo o Docker) |
|---|---|:---:|:---:|:---:|
| **Modo 1 — URL** | URL con `AuthToken` que genera la DIAN | ✅ | ✅ | ✅ |
| **Modo 2 — Certificado** | Certificado `.p12` (abre/manipula un navegador) | ✅ | ✅ | ❌ |

> **⚠️ En VPS (nativo o Docker) solo funciona el Modo 1 (URL).** El Modo 2 requiere
> gestión de ventanas del navegador y NO funciona en entornos sin interfaz gráfica.

---

## Requisitos

- **Node 18.17.0** (instalaciones nativas: Windows, Ubuntu local y VPS sin Docker).
  La imagen Docker trae su propio Node 18.12.1 (funciona correctamente, no hace falta cambiarlo).
- **Google Chrome (stable)** instalado — necesario en **todos** los entornos.
  - Windows: ruta estándar `C:\Program Files\Google\Chrome\Application\chrome.exe`.
  - Linux: `google-chrome-stable` (los scripts lo instalan).
- **libnss3-tools** (`pk12util`, `certutil`) — solo en Linux y solo para el Modo 2.

> El servicio se relanza solo con `--openssl-legacy-provider` (necesario para los
> certificados con algoritmos legados de la DIAN), así que basta con `node server.js`.

---

## Instalación

Elegí la sección según tu entorno:

- [Windows (local)](#windows-local) — Modo 1 y Modo 2
- [Ubuntu local (con GUI)](#ubuntu-local-con-gui) — Modo 1 y Modo 2
- [VPS Ubuntu — nativo (sin Docker)](#vps-ubuntu--nativo-sin-docker) — solo Modo 1
- [VPS Ubuntu — con Docker](#vps-ubuntu--con-docker) — solo Modo 1

> **Windows vs Linux local:** los pasos son los **mismos a nivel conceptual** (instalar
> Chrome → instalar Node 18.17.0 → `npm install` → `node server.js` → importar el `.p12`
> para el Modo 2), pero **los comandos cambian**: en Windows se usa el instalador `.exe`
> de Chrome y nvm-windows (no hay `apt`); en Linux se usa `apt` + el `.deb` de Chrome y
> hace falta `libnss3-tools` para el Modo 2.

---

### Windows (local)

Soporta **Modo 1 y Modo 2**.

1. Instalar **Google Chrome** en la ruta estándar (`C:\Program Files\Google\Chrome\...`).
2. Instalar **Node 18.17.0** (recomendado vía [nvm-windows](https://github.com/coreybutler/nvm-windows)).
3. En la carpeta del proyecto:

   ```powershell
   npm install
   node server.js          # o:  npm start
   ```

4. Abrir `http://localhost:3000/`

> Para el **Modo 2** ver [Configuración del certificado](#configuración-del-certificado-modo-2).

---

### Ubuntu local (con GUI)

Soporta **Modo 1 y Modo 2**.

**Instalación automática (recomendado):**

```bash
git clone https://git.buho.la/facturalatam/extract-documents-dian-v2.git
cd extract-documents-dian-v2/
chmod +x scripts/install-ubuntu-local.sh
./scripts/install-ubuntu-local.sh
```

El script [scripts/install-ubuntu-local.sh](scripts/install-ubuntu-local.sh) valida e instala cada
dependencia antes de instalarla (paquetes apt, `libnss3-tools` para el Modo 2, Chrome,
NVM, Node 18.17.0, `npm install`). Es idempotente. Al terminar:

```bash
node server.js
# acceder a http://localhost:3000/
```

**Instalación manual (referencia):**

<details>
<summary>Pasos manuales equivalentes</summary>

```bash
sudo apt update
sudo apt install -y wget unzip curl git libnss3-tools

curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm install 18.17.0
nvm use 18.17.0

wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y ./google-chrome-stable_current_amd64.deb

git clone https://git.buho.la/facturalatam/extract-documents-dian-v2.git
cd extract-documents-dian-v2/
PUPPETEER_SKIP_DOWNLOAD=true npm install

node server.js
```
</details>

---

### VPS Ubuntu — nativo (sin Docker)

Solo **Modo 1 (URL)**. Corre con PM2.

**Instalación automática (recomendado):**

```bash
git clone https://git.buho.la/facturalatam/extract-documents-dian-v2.git
cd extract-documents-dian-v2/
chmod +x scripts/install-ubuntu-vps.sh
./scripts/install-ubuntu-vps.sh
```

El script [scripts/install-ubuntu-vps.sh](scripts/install-ubuntu-vps.sh) valida cada dependencia antes
de instalarla (paquetes apt, Chrome, NVM, Node 18.17.0, PM2, `npm install`). Es
idempotente. Al terminar, levantar el servicio con:

```bash
pm2 start server.js --name extractor
pm2 startup
pm2 save
# acceder a http://[IP_VPS]:3000
```

**Instalación manual (referencia):**

<details>
<summary>Pasos manuales equivalentes</summary>

```bash
sudo apt update
sudo apt install -y \
    ca-certificates fonts-liberation \
    libappindicator3-1 libasound2 libatk-bridge2.0-0 libatk1.0-0 libcups2 \
    libdbus-1-3 libgdk-pixbuf2.0-0 libnspr4 libnss3 libx11-xcb1 libxcomposite1 \
    libxdamage1 libxrandr2 xdg-utils libu2f-udev libvulkan1 libgbm1 libxshmfence1 \
    wget unzip curl git

wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y ./google-chrome-stable_current_amd64.deb

curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
source ~/.bashrc
nvm install 18.17.0
nvm use 18.17.0
nvm alias default 18.17.0
npm install -g pm2

git clone https://git.buho.la/facturalatam/extract-documents-dian-v2.git
cd extract-documents-dian-v2/
PUPPETEER_SKIP_DOWNLOAD=true npm install

pm2 start server.js --name extractor
pm2 startup
pm2 save
```
</details>

---

### VPS Ubuntu — con Docker

Solo **Modo 1 (URL)**, **sin dominio** (acceso directo por `IP:3000`). El script instala
Docker + docker-compose, clona el repo en `./extractor/`, genera el `docker-compose.yml`
y levanta el contenedor expuesto en el puerto 3000.

Elegí el script según la versión de Ubuntu del VPS:

| Versión del VPS | Script |
|---|---|
| Ubuntu 22 | [scripts/install-extractor-ubuntu22.sh](scripts/install-extractor-ubuntu22.sh) |
| Ubuntu 20 | [scripts/install-extractor-ubuntu20.sh](scripts/install-extractor-ubuntu20.sh) |

En el VPS, ubicate en el directorio donde querés instalar el extractor y seguí estos
pasos (sin descargas: el cliente crea el script a mano):

1. **Crear el archivo del script** y pegar adentro el contenido del script
   correspondiente a tu versión de Ubuntu (copialo del repo:
   [scripts/install-extractor-ubuntu22.sh](scripts/install-extractor-ubuntu22.sh) o
   [scripts/install-extractor-ubuntu20.sh](scripts/install-extractor-ubuntu20.sh)):

   ```bash
   nano install-extractor-ubuntu22.sh
   # pegar el contenido del script y guardar  (Ctrl+O, Enter, Ctrl+X)
   ```

2. **Darle permisos de ejecución:**

   ```bash
   chmod +x install-extractor-ubuntu22.sh
   ```

3. **Ejecutarlo** en la raíz (el directorio donde lo creaste):

   ```bash
   ./install-extractor-ubuntu22.sh
   ```

> El script clona el repositorio en `./extractor/` dentro del directorio donde lo
> ejecutes y levanta el contenedor. No lo ejecutes dentro de un repo ya clonado.

**Pasos del script (4 fases):** dependencias del sistema + Docker + docker-compose →
clonar repo → generar `.env` (con `CHROME_HEADLESS=false`) y `docker-compose.yml`
(sin dominio, puerto 3000) → `docker-compose up -d --build`. Al terminar deja un resumen
en `./extractor/setup/private.txt` y se accede en `http://[IP_VPS]:3000`.

**Configuración (variables de entorno):** el contenedor toma su configuración del archivo
`./extractor/.env` (el script lo crea con los defaults; `CHROME_HEADLESS=false` porque
corre bajo xvfb). Para cambiar parámetros (clave de API, concurrencia, etc.) editá ese
`.env` y reiniciá:

```bash
cd extractor
nano .env
docker-compose up -d        # aplica los cambios del .env
```

**Notas:**

- El script es idempotente: reejecutarlo no reinstala paquetes y actualiza el repo con
  `git pull`. Si el `.env` ya existe, no lo pisa (respeta tu configuración).
- Solo Modo 1 (URL) funciona en VPS. El Modo 2 (certificado) requiere GUI.

---

## Configuración del certificado (Modo 2)

> El Modo 2 (certificado) solo aplica a entornos con interfaz gráfica: **Windows** y
> **Ubuntu local**. En VPS no está disponible.

- **Windows:** instalar el `.p12` haciendo doble click y seguir los pasos.
- **Ubuntu local:** en Chrome ir a `chrome://certificate-manager` → menú "Tus certificados" → Importar.

> En Ubuntu local puede que tengas que ejecutar el botón de extracción varias veces la
> primera vez, por la configuración previa que requiere Chrome.

---

## Guía de uso

### Modo 1 — Extracción por URL

1. Ingresar a la DIAN con las credenciales del usuario para obtener la URL.
2. Verificar el correo del usuario que posee la URL; luce como
   `https://catalogo-vpfe.dian.gov.co/User/AuthToken?pk=10910094%7C1004121459&rk=901846280&token=69949bf5-...`
3. Copiar esa URL en el formulario.
4. Presionar **"Procesar"**: el proceso se muestra en la pantalla negra.
5. Al terminar, se obtiene el Excel con la información.

### Modo 2 — Extracción por certificado

1. Según si extraés en modo habilitación o producción, logueate previamente con la
   opción de certificado dentro del portal de la DIAN.
2. Este método abre un navegador que necesita el acceso a la DIAN ya iniciado (por eso
   se requiere el login previo, para no interrumpir el flujo).
3. Al presionar **"Procesar"** el navegador se manipula de forma automática (sin
   intervención) y se cierra al finalizar.
4. Al terminar, se obtiene el Excel con la información.

---

## API

Los endpoints bajo `/api/*` aceptan autenticación por **API Key**: si la variable
`API_SECRET_KEY` está definida en el servidor, hay que enviar el mismo valor en el
header `X-Api-Key` (o `Authorization: Bearer <key>`). Si no está definida, los
endpoints son públicos.

| Método | Endpoint | Descripción |
|---|---|---|
| `POST` | `/api/scrape` | Extracción por URL (Modo 1). Soporta `async: true` → devuelve `jobId`. |
| `POST` | `/api/certificate` | Extracción por certificado (Modo 2). |
| `POST` | `/api/cufes` | Obtiene los CUFEs del **listado** sin descargar ZIPs (Modo 1). Soporta `async: true` → devuelve `jobId`. |
| `POST` | `/api/xml-by-cufes` | Saca los CUFEs del listado y pide los XML a un **API externo** (con certificado) → arma el reporte, sin descargar ZIPs. Soporta `async: true`. |
| `POST` | `/api/extract-cufes` | Extrae CUFEs de los **ZIP ya descargados** en una carpeta de sesión. |
| `POST` | `/api/system/consult` | Endpoint unificado: `metodo` = `url` / `certificate` / `cufes`. Soporta `async: true`. |
| `GET`  | `/api/jobs/:id` | Estado de un job asíncrono (`running` / `done` / `error`). |
| `GET`  | `/api/jobs` | Lista de jobs. |

- Colección de requests lista para usar: [requests.http](requests.http) (extensión
  *REST Client* en VS Code).
- Documentación de los 2 modos en Postman:
  [ver colección](https://documenter.getpostman.com/view/1431398/2sAY4uCido#401fa60a-ba4d-4763-bf8b-e0ffbc48ef9f)

### `POST /api/cufes` — CUFEs del listado

Obtiene el **CUFE** de cada factura del listado (método token/URL) **sin descargar los
ZIP** (es el `data-id` de cada fila, idéntico al CUFE del detalle). Es rápido. Además
deja en la carpeta de la sesión un `resultado_<fecha>.json` y un `.xlsx`.

**Body (JSON):**

| Campo | Req. | Descripción |
|---|---|---|
| `url` | ✅ | Link `AuthToken?...` que la DIAN envía por correo |
| `tipo` | ✅ | `"Received"` (recibidas) o `"Sent"` (enviadas) |
| `fechaDesde` / `fechaHasta` | ✅ | `YYYY-MM-DD` |
| `excluirEstados` | ❌ | Estados RADIAN a excluir, separados por coma (ej: `"Acuse,Rechazo"`) |
| `async` | ❌ | `true` → responde `jobId` (202) y se consulta el resultado en `/api/jobs/:id` |

**Respuesta síncrona (200):** `{ success, partial, message, total, folder, log, data[] }`.
Cada item de `data[]`:

| Campo | Ejemplo | Descripción |
|---|---|---|
| `cufe` | `1f702889…613a1bfe` | **El CUFE** (96 hex) |
| `tipoDoc` | `"01"` | Código de tipo de documento |
| `partitionKey` | `co\|25\|1f7` | Clave de partición interna DIAN |
| `fechaEmision` / `fechaRecepcion` | `25-05-2026` | Fechas de emisión / recepción |
| `grupo` / `folio` | `FE` / `FE2029` | Prefijo y número de factura |
| `descripcion` | `Factura electrónica` | Descripción del tipo |
| `nitEmisor` / `nombreEmisor` | `900805709` / `Source Solutions SAS` | Emisor |
| `nitReceptor` / `nombreReceptor` | `901846280` / `Digital Buho SAS` | Receptor |
| `estado` | `Aprobado con notificación` | Estado del documento |
| `EstadoRadian` | `Factura Electrónica` | Estado RADIAN (lo que filtra `excluirEstados`) |
| `valor` | `$ 1.580.000` | Valor total (texto, tal como lo muestra la DIAN) |

En modo `async`, el mismo array de CUFEs llega en el campo `data` del job
(`GET /api/jobs/:id`). También está disponible vía `POST /api/system/consult` con
`"metodo": "cufes"`. Ejemplos ejecutables: [requests.http](requests.http) (sección 3.d).

### `POST /api/xml-by-cufes` — CUFEs del listado → API XML → reporte

Método alternativo que **no descarga los ZIP de la DIAN** (evita el anti-bot lento de
~90 s/línea). El extractor:

1. saca los **CUFEs del listado** con el método token/URL (igual que `/api/cufes`);
2. le pide los **XML** de esos CUFEs a un **API externo** (`XML_QUERY_API_URL`), que los
   consulta en la DIAN con el **certificado** (`.p12`) + contraseña;
3. **parsea** los XML (mismo `parseUblXml` de siempre) y arma el **mismo reporte**
   (JSON + Excel) que los demás métodos.

Requiere configurar en el servidor `XML_QUERY_API_URL` (y normalmente `XML_QUERY_API_KEY`).
El **ambiente** DIAN (1 = producción, 2 = habilitación) se **deriva de la `url`** (host con
`-hab` → 2, si no → 1); se puede forzar con el campo `ambiente`.

**Body (JSON):**

| Campo | Req. | Descripción |
|---|---|---|
| `url` | ✅ | Link `AuthToken?...` de DIAN (de donde salen los CUFEs; define el ambiente) |
| `tipo` | ✅ | `"Received"` (recibidas) o `"Sent"` (enviadas) |
| `fechaDesde` / `fechaHasta` | ✅ | `YYYY-MM-DD` |
| `p12Base64` | ✅ | `.p12`/`.pfx` en base64 (para que el API consulte la DIAN) |
| `p12Password` | ✅ | Contraseña del `.p12` |
| `ambiente` | ❌ | `1` = prod, `2` = hab. Si se omite, se deriva de la `url` |
| `excluirEstados` | ❌ | Estados RADIAN a excluir, separados por coma (ej: `"Acuse,Rechazo"`) |
| `async` | ❌ | `true` → responde `jobId` (202) y se consulta el resultado en `/api/jobs/:id` |

**Respuesta síncrona (200):** `{ success, partial, message, total, cufesTotal, ok, fail, folder, log, data[] }`.

- `data[]` son los **documentos UBL parseados** (mismo shape que `/api/scrape` y `/api/certificate`).
- `cufesTotal` = CUFEs hallados en el listado; `ok`/`fail` = XML devueltos / CUFEs sin XML por el API.
- Los fallos por-CUFE **no rompen el lote**: se arma el reporte con los XML que sí llegaron.

Ejemplos ejecutables: [requests.http](requests.http) (sección 3.e).

---

## Variables de entorno

Todas son opcionales; los valores por defecto sirven para la mayoría de los casos.

### Cómo configurarlas

La forma más fácil es un archivo **`.env`** en la raíz del proyecto (el server lo lee
solo, vía `dotenv`):

```bash
cp .env.example .env
nano .env          # descomentá y ajustá lo que necesites
```

El [.env.example](.env.example) ya viene con las variables más comunes y sus valores por
defecto configurados — copialo y cambiá solo lo que necesites. La [tabla de referencia](#referencia)
de abajo lista **todas** las variables (incluidas las automáticas/avanzadas que no están en el example).

> Si además definís una variable directamente en el entorno (PM2, la terminal, o un
> bloque `environment:` en el `docker-compose.yml`), esa **gana** sobre el `.env` — útil
> para pisar un valor puntual. El `.env` está en `.gitignore` (no se versiona); el
> `.env.example` sí.

Según el entorno:

- **Windows (local):** `cp .env.example .env` y editar, o `$env:API_SECRET_KEY="mi-clave"; node server.js`
- **Linux + PM2:** `.env` en la raíz del proyecto (lo lee solo), o `API_SECRET_KEY="mi-clave" pm2 start server.js --name extractor && pm2 save`
- **Docker:** editá el `./extractor/.env` — el `docker-compose.yml` que genera el script lo carga con `env_file`. Reiniciá con `docker-compose up -d` para aplicar.

### Referencia

| Variable | Default | Descripción |
|---|---|---|
| `API_SECRET_KEY` | *(sin clave)* | Si se define, exige el header `X-Api-Key` en `/api/*`. |
| `MAX_CONCURRENT_SESSIONS` | `3` | Máximo de Chrome concurrentes (~200-400 MB c/u); el resto va a cola FIFO. |
| `CHROME_HEADLESS` | `true` (Linux) | `false` para abrir Chrome con ventana (en Docker se setea en `false`). |
| `CHROME_BASE_PROFILE` | `C:\chrome-profile` / `~/chrome-profile` | Perfil base con el certificado DIAN ya instalado. |
| `TZ` | `America/Bogota` | Zona horaria para los nombres de carpeta/sesión. |
| `CLEANUP_MAX_AGE_HOURS` | `24` | Antigüedad máxima de las descargas antes de borrarlas. |
| `CLEANUP_INTERVAL_HOURS` | `6` | Frecuencia del cleanup automático. |
| `JOB_TTL_HOURS` | `2` | Tiempo de vida de los jobs asíncronos en memoria. |
| `LOG_MAX_LINES` | `1000` | Líneas máximas del buffer de logs por job. |
| `XML_QUERY_API_URL` | *(vacío)* | URL base del API externo de XML por CUFEs. **Requerido** para `/api/xml-by-cufes`. |
| `XML_QUERY_API_KEY` | *(vacío)* | Clave que ese API exige en su header `X-Api-Key`. |
| `XML_QUERY_API_PATH` | `/api/ubl2.1/xml/by-cufes` | Ruta del endpoint del API externo. |
| `XML_QUERY_BATCH_SIZE` | `500` | CUFEs por request al API externo (máx 500). |
| `XML_QUERY_TIMEOUT_MS` | `120000` | Timeout por request al API externo (ms). |

> El servidor escucha siempre en el **puerto 3000** (no configurable por env).

---

## Para agregar .env

Cuando el extractor ya está corriendo e implementar el archivo .env

### Docker

```bash
cd extractor
git pull                              # CLAVE: trae el código nuevo (server.js con dotenv, package.json, etc.)
[ -f .env ] || cp .env.example .env   # crea el .env solo si todavía no existe
nano .env                             # (opcional) ajustar parámetros
docker-compose down
docker-compose up -d --build          # rebuild: el npm install instala dotenv y arranca el código nuevo
```

- Para cambios **posteriores solo del `.env`** (sin tocar código) no hace falta recrear,
  alcanza con reiniciar el proceso:
  ```bash
  docker exec node_extractor pm2 restart extractor
  ```

### Nativo (PM2 / sin Docker)

```bash
cd extractor
git pull
npm install                           # CLAVE: instala dotenv (dependencia nueva)
[ -f .env ] || cp .env.example .env
pm2 restart extractor
```