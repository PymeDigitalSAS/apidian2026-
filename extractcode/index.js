#!/usr/bin/env node

// rebrowser-puppeteer-core: fork parcheado de puppeteer que NO deja la huella de
// CDP (Runtime.enable) que detectan los WAF anti-bot (Azure WAF de la DIAN).
// Fallback a puppeteer normal si aún no se instaló (no rompe el arranque).
let puppeteer;
let motorAntibot = 'rebrowser-puppeteer-core (parche anti-CDP activo)';
try {
  puppeteer = require('rebrowser-puppeteer-core');
} catch (_) {
  puppeteer = require('puppeteer');
  motorAntibot = 'puppeteer normal (SIN parche — falta instalar rebrowser-puppeteer-core)';
}
const fs = require('fs');
const path = require('path');
const args = require('minimist')(process.argv.slice(2));
const logger = require('./logger');
const axios = require('axios');

// Log técnico: va SOLO a la consola del server (no al log visual de la UI).
// server.js reconoce el prefijo "DEBUG_ONLY:" y no lo reenvía a la interfaz.
const debugLog = (msg) => logger.emit('log', `DEBUG_ONLY:${msg}`);

const { processFolder, writeCufesResult } = require('./unzip');
const { FILA_REAL, leerFilaKey, volverAlListadoSiHaceFalta } = require('./dian-portal-helpers');

const now = new Date();
const datetime = now.toISOString().replace(/[:.]/g, '-').slice(0,19); // yyyy-mm-ddThh-mm-ss

const url = args.url;
const fechaDesde = args.fechaDesde;
const fechaHasta = args.fechaHasta;
const DEBUGGING_PORT = parseInt(args.debuggingPort, 10) || 9229;

if (!url) {
  logger.emit('log', '❌ Debes proporcionar --url');
  process.exit(1);
}

const tipo = args.tipo || 'Sent'; // valor por defecto
// Modo de operación: 'download' (default, baja los ZIPs) o 'cufes' (solo lee el
// CUFE del data-id de cada fila del listado y lo devuelve, sin descargar).
const mode = args.mode || 'download';
const excluirEstados = (args.excluirEstados || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
const domain = new URL(url).origin; // ejemplo: https://catalogo-vpfe.dian.gov.co
const url2 = `${domain}/Document/${tipo}`;

// Pausas anti-bloqueo DIAN. La DIAN bloquea ("Solicitud bloqueada por controles
// de seguridad") cuando detecta descargas demasiado rápidas / robóticas. Se hace
// una pausa entre CADA documento y otra (mayor) al cambiar de página; a cada
// pausa se le suma un tiempo aleatorio de 0..DELAY_JITTER_MS para que el ritmo no
// sea robótico. Subí los valores en el .env si el bloqueo persiste.
const DELAY_DESCARGA_MS = parseInt(args.delayDescarga || process.env.DELAY_DESCARGA_MS, 10) || 8000;
const DELAY_PAGINA_MS   = parseInt(args.delayPagina   || process.env.DELAY_PAGINA_MS,   10) || 12000;
const DELAY_JITTER_MS   = parseInt(args.delayJitter   || process.env.DELAY_JITTER_MS,   10) || 4000;
// Espera larga (~90 s) entre CADA línea/descarga. La DIAN ahora redirige a una
// vista anti-bot al descargar y bloquea si el ritmo es muy rápido. 90000 = 90 s.
const DELAY_LINEA_MS    = parseInt(args.delayLinea    || process.env.DELAY_LINEA_MS,    10) || 90000;
// Modo CUFE: no descarga nada, así que puede ir rápido. Pausa corta entre páginas.
const DELAY_CUFES_MS    = parseInt(args.delayCufes    || process.env.DELAY_CUFES_MS,    10) || 1500;
// Pausa con jitter: base + aleatorio(0..DELAY_JITTER_MS).
const pausaAntibloqueo = (baseMs) =>
  new Promise(r => setTimeout(r, baseMs + Math.floor(Math.random() * (DELAY_JITTER_MS + 1))));

const downloadsRoot = path.resolve(__dirname, 'downloads');
if (!fs.existsSync(downloadsRoot)) fs.mkdirSync(downloadsRoot);

// Si el server pasa --downloadPath se usa la carpeta de la sesión.
// Caso contrario, fallback al timestamp (modo standalone).
const downloadPath = args.downloadPath
  ? path.resolve(args.downloadPath)
  : path.resolve(__dirname, 'downloads', datetime);

// Espera a que termine una descarga del navegador: el conteo de .zip aumenta
// respecto a `beforeCount` y no quedan .crdownload (descarga parcial de Chrome).
// Tras detectar la finalización, abre el archivo nuevo en modo lectura para
// confirmar que Chrome ya soltó el handle (Windows puede mantenerlo brevemente
// después de renombrar .crdownload → .zip).
async function waitForDownload(dir, beforeCount, maxWaitMs = 30000) {
  const start = Date.now();
  while (Date.now() - start < maxWaitMs) {
    let files;
    try { files = fs.readdirSync(dir); } catch (_) { files = []; }
    const zips = files.filter(f => f.toLowerCase().endsWith('.zip'));
    const hasPartial = files.some(f => f.toLowerCase().endsWith('.crdownload'));
    if (zips.length > beforeCount && !hasPartial) {
      // Confirmar que el archivo nuevo es legible (Chrome liberó el handle).
      const newest = zips
        .map(f => ({ name: f, mtime: fs.statSync(path.join(dir, f)).mtimeMs }))
        .sort((a, b) => b.mtime - a.mtime)[0];
      try {
        const fd = fs.openSync(path.join(dir, newest.name), 'r');
        fs.closeSync(fd);
        return true;
      } catch (_) {
        // Archivo aún bloqueado; seguir esperando.
      }
    }
    await new Promise(r => setTimeout(r, 250));
  }
  return false;
}

let exitCode = 0;
let totalDocuments = 0;
let cufes = []; // acumulador del modo CUFE (mode === 'cufes')
let browser;

(async () => {
logger.emit('log', '✅ Iniciando proceso');
debugLog(`🧩 Motor: ${motorAntibot}`);
try {
  if (!fs.existsSync(downloadPath)) fs.mkdirSync(downloadPath);


  let webSocketDebuggerUrl;
  let retries = 0;
  const MAX_RETRIES = 30;
  while (!webSocketDebuggerUrl && retries < MAX_RETRIES) {
    try {
      const response = await axios.get(`http://127.0.0.1:${DEBUGGING_PORT}/json/version`);
      webSocketDebuggerUrl = response.data.webSocketDebuggerUrl;
    } catch (err) {
      retries++;
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
  }
  if (!webSocketDebuggerUrl) {
    logger.emit('log', `❌ No se pudo conectar a Chrome después de ${MAX_RETRIES} segundos`);
    process.exit(1);
  }

  browser = await puppeteer.connect({
    browserWSEndpoint: webSocketDebuggerUrl,
    defaultViewport: null,
  });

  // const browser = await puppeteer.launch({
  //   headless: 'new', // Modo headless moderno
  //   args: [
  //     '--no-sandbox',
  //     '--disable-setuid-sandbox',
  //     '--disable-notifications',
  //     '--window-size=1920,1080',
  //   ],
  //   defaultViewport: null
  // });

  const [page] = await browser.pages();

  const client = await page.target().createCDPSession();
  await client.send('Page.setDownloadBehavior', {
    behavior: 'allow',
    downloadPath: downloadPath,
  });

  // ── Anti-bot Cloudflare ──────────────────────────────────────────────
  // La DIAN protege el portal con Cloudflare. La PRIMERA navegación suele
  // recibir un desafío ("Checking your browser…"); si saltamos a /Document/
  // antes de que termine, la DIAN responde con el LOGIN en vez del portal.
  // Al superar el desafío, Cloudflare emite la cookie cf_clearance que queda
  // en ESTE navegador, así que reintentar la secuencia dentro del mismo
  // proceso entra (es lo mismo que pasa cuando uno reintenta a mano y "a la
  // 2ª funciona"; acá lo hacemos automático y en el navegador donde la
  // clearance ya está presente).

  // Espera a que Cloudflare termine su verificación (iframe del challenge o
  // títulos típicos de la interstitial). No falla si nunca aparece challenge.
  // Espera a que el WAF/anti-bot termine su verificación.
  //  • Si NO hay desafío → retorna al instante (camino feliz, sin demora).
  //  • Si detecta un desafío activo (iframe de challenge o título tipo
  //    "Azure WAF"/"Un momento") → espera hasta maxDesafioMs a que se resuelva
  //    y emita la cookie de clearance. Esto es clave para que la PRIMERA vez en
  //    frío (perfil sin cookie) pase sola, sin tener que correr una 2ª vez.
  async function esperarCloudflare(pg, maxDesafioMs = 30000) {
    const inicio = Date.now();
    while (true) {
      const challenge = pg.frames().some(f => (f.url() || '').includes('challenges.cloudflare.com'));
      const title = await pg.title().catch(() => '');
      const enDesafio = challenge || /just a moment|checking your browser|un momento|verificando|azure waf|waf/i.test(title);
      if (!enDesafio) return;                          // ya pasó (o nunca hubo)
      if (Date.now() - inicio > maxDesafioMs) return;  // no se resolvió a tiempo; el chequeo de portal decide
      await new Promise(r => setTimeout(r, 500));
    }
  }

  // ¿Cargó el portal de documentos (y no el login)? El picker de fechas solo
  // existe estando autenticado dentro del portal.
  async function enPortalDocumentos(pg) {
    return pg.waitForSelector('#dashboard-report-range', { timeout: 5000 })
      .then(() => true).catch(() => false);
  }

  const MAX_INTENTOS = 3;
  let enDocumentos = false;
  for (let intento = 1; intento <= MAX_INTENTOS && !enDocumentos; intento++) {
    logger.emit('log', intento === 1
      ? '🟡 Ingresando a DIAN'
      : `🟡 Reintentando ingreso (Cloudflare) — intento ${intento}/${MAX_INTENTOS}`);
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 100000 });
    await esperarCloudflare(page); // dejar que Cloudflare/AuthToken completen antes de saltar
    debugLog(`🔎 Tras token → ${page.url()} | "${await page.title().catch(() => '')}"`);

    logger.emit('log', '🟢 Redirigiendo a documentos');
    await page.goto(url2, { waitUntil: 'networkidle2', timeout: 100000 });
    await esperarCloudflare(page);
    debugLog(`🔎 Tras /Document → ${page.url()} | "${await page.title().catch(() => '')}"`);

    enDocumentos = await enPortalDocumentos(page);
    if (!enDocumentos && intento < MAX_INTENTOS) {
      logger.emit('log', '⚠️ La DIAN devolvió el login (bloqueo del WAF anti-bot). Reintentando…');
      await new Promise(r => setTimeout(r, 1500));
    }
  }

  if (!enDocumentos) {
    // Diagnóstico: guardar captura + HTML de la pantalla que recibió el bot,
    // para distinguir Cloudflare ("Just a moment"/Turnstile) de un login real
    // de la DIAN o de un error. Queda en la carpeta de descargas de la sesión.
    try {
      await page.screenshot({ path: path.join(downloadPath, '_debug-bloqueo.png') });
      fs.writeFileSync(path.join(downloadPath, '_debug-bloqueo.html'), await page.content());
      logger.emit('log', `🧪 Diagnóstico guardado: _debug-bloqueo.png / .html en la carpeta de la sesión`);
      logger.emit('log', `🧪 URL final: ${page.url()}`);
    } catch (e) {
      logger.emit('log', `🧪 No se pudo guardar diagnóstico: ${e.message}`);
    }
    throw new Error('La DIAN redirige al login: el WAF anti-bot (Azure WAF) bloqueó el navegador automatizado. La URL/token es válida (entra en un navegador normal), pero el portal no se abrió tras varios intentos.');
  }

  function formatFecha(fecha) {
    return fecha.replaceAll('-', '/'); // convierte 2025-06-08 → 2025/06/08
  }

  // El portal DIAN usa un setTimeout para disparar la carga inicial de datos
  // (con las fechas por defecto). networkidle2 resuelve ANTES de que ese timeout
  // dispare, así que el AJAX inicial empieza después de que networkidle2 termina.
  // Debemos esperar que ese AJAX inicial arranque y termine antes de tocar el picker,
  // de lo contrario su callback sobreescribirá nuestras fechas.
  //
  // Paso 1: esperar que el loader aparezca (AJAX inicial arrancó)
  await page.waitForFunction(() => {
    const el = document.querySelector('#tableDocuments_processing');
    return el !== null && el.style.display !== 'none' && el.style.display !== '';
  }, { timeout: 12000 }).catch(() => {});
  // Paso 2: esperar que el loader desaparezca (AJAX inicial terminó)
  await page.waitForFunction(() => {
    const el = document.querySelector('#tableDocuments_processing');
    return !el || el.style.display === 'none' || el.style.display === '';
  }, { timeout: 60000 }).catch(() => {});

  // Esperar que el plugin jQuery DateRangePicker esté inicializado en el input
  await page.waitForFunction(() => {
    return typeof $ !== 'undefined' &&
      $('#dashboard-report-range').data('daterangepicker') !== undefined;
  }, { timeout: 15000 });

  const rango = `${formatFecha(fechaDesde)} - ${formatFecha(fechaHasta)}`;
  const tipos = { 'Sent': 'Enviados', 'Received': 'Recibidos' };

  // Setear fechas Y hacer click en "Buscar" en un solo evaluate() sincrónico.
  //
  // El formulario de DIAN tiene campos ocultos StartDate/EndDate que se actualizan
  // cuando el picker dispara 'apply.daterangepicker'. Sin ese evento los campos
  // conservan las fechas por defecto y el POST lleva fechas incorrectas al servidor.
  // El método de certificado funciona porque tipear en el input dispara ese evento
  // automáticamente; aquí debemos dispararlo manualmente + sobreescribir los campos.
  const clicked = await page.evaluate((desde, hasta, rangoStr) => {
    const el = $('#dashboard-report-range');
    const picker = el.data('daterangepicker');
    if (picker) {
      picker.setStartDate(desde);
      picker.setEndDate(hasta);
      el.val(rangoStr);
      // Disparar apply para que los handlers de DIAN actualicen StartDate/EndDate
      el.trigger('apply.daterangepicker', [picker]);
    } else {
      el.val(rangoStr).trigger('change');
    }
    // Sobreescribir directamente los campos ocultos del form.
    // Formato que espera DIAN: M/D/YYYY h:mm:ss AM/PM (sin ceros a la izquierda)
    // desde/hasta vienen como YYYY/MM/DD
    const pd = desde.split('/').map(Number); // [YYYY, MM, DD]
    const ph = hasta.split('/').map(Number);
    $('input[name="StartDate"]').val(`${pd[1]}/${pd[2]}/${pd[0]} 12:00:00 AM`);
    $('input[name="EndDate"]').val(`${ph[1]}/${ph[2]}/${ph[0]} 11:59:59 PM`);

    const btn = document.querySelector('.btn.btn-success.btn-radian-success');
    if (btn) { btn.click(); return true; }
    return false;
  }, formatFecha(fechaDesde), formatFecha(fechaHasta), rango);

  logger.emit('log', `📅 Filtro de fechas ingresado: ${rango}`);

  if (clicked) {
    logger.emit('log', `🔍 Buscando documentos "${tipos[tipo] || tipo}" entre ${rango}`);
    await new Promise(resolve => setTimeout(resolve, 8000));

    // Esperar a que el loader desaparezca
    await page.waitForFunction(() => {
      const el = document.querySelector('#tableDocuments_processing');
      return el && el.style.display === 'none';
    }, { timeout: 100000 });

    // Luego esperar que se renderice al menos una fila nueva
    await page.waitForSelector('#tableDocuments tbody tr', { timeout: 100000 });
  }

  // En modo CUFE NO entramos al loop de descarga (queda intacto abajo): usamos un
  // loop propio de recolección y salimos.
  let hasNextPage = mode !== 'cufes';
  let currentPage = 1;

  if (mode === 'cufes') {
    let pag = 1;
    let hayMas = true;
    while (hayMas) {
      logger.emit('log', `📄 Página ${pag}`);
      await page.waitForSelector('#tableDocuments_wrapper');
      await new Promise(r => setTimeout(r, 2000));

      // El CUFE ya está en el listado: es el atributo data-id de cada <tr>
      // (idéntico al que muestra el detalle). Leemos data-id + columnas de TODAS
      // las filas de una vez, sin clicks ni navegación.
      const registros = await page.$$eval('#tableDocuments tbody tr.document-row', trs => trs.map(tr => {
        const tds = tr.querySelectorAll('td');
        const t = i => (tds[i] ? tds[i].textContent.trim().replace(/\s+/g, ' ') : '');
        return {
          cufe: tr.getAttribute('data-id') || '',
          tipoDoc: tr.getAttribute('data-type') || '',
          partitionKey: tr.getAttribute('data-partitionkey') || '',
          fechaEmision: t(1), fechaRecepcion: t(2), grupo: t(3), folio: t(4), descripcion: t(5),
          nitEmisor: t(6), nombreEmisor: t(7), nitReceptor: t(8), nombreReceptor: t(9),
          estado: t(10), EstadoRadian: t(11), valor: t(12),
        };
      }));

      let nuevos = 0;
      for (const r of registros) {
        if (excluirEstados.length > 0 && excluirEstados.includes((r.EstadoRadian || '').toLowerCase())) continue;
        if (!r.cufe) continue;
        cufes.push(r);
        nuevos++;
      }
      logger.emit('log', `🧾 CUFEs en página ${pag}: ${nuevos} (acumulado: ${cufes.length})`);

      const nextBtn = await page.$('button[data-dt-idx="next"]');
      const isDisabled = !nextBtn || await page.evaluate(el => el.classList.contains('disabled') || el.getAttribute('aria-disabled') === 'true', nextBtn);
      if (isDisabled) {
        hayMas = false;
        logger.emit('log', '✅ Fin del paginado');
      } else {
        logger.emit('log', '➡️ Siguiente página...');
        await nextBtn.click();
        await pausaAntibloqueo(DELAY_CUFES_MS);
        pag++;
      }
    }
    logger.emit('log', `✅ CUFEs recolectados: ${cufes.length}`);
  }

  while (hasNextPage) {
    logger.emit('log', `📄 Página ${currentPage}`);
    // Esperar que cargue la tabla y botones
    await page.waitForSelector('#tableDocuments_wrapper');
    await new Promise(resolve => setTimeout(resolve, 8000));

    const rowCount = await page.$$eval(FILA_REAL, rows => rows.length);
    logger.emit('log', `🔍 Filas encontradas: ${rowCount}`);

    let downloadable = 0;
    let skipped = 0;

    for (let i = 0; i < rowCount; i++) {
      try {
        // Re-buscar las filas en CADA iteración: si una descarga anterior disparó
        // la vista anti-bot, la página navegó y los handles previos quedaron
        // detached. Al volver, el listado queda igual (mismo filtro/página), así
        // que el índice i sigue apuntando a la misma fila lógica.
        const rows = await page.$$(FILA_REAL);
        if (i >= rows.length) {
          logger.emit('log', `⚠️ La fila ${i + 1} ya no está en el listado; se corta esta página`);
          break;
        }
        const row = rows[i];

        // Leer el estado RADIAN (columna 12)
        const estadoRadian = await row.$eval('td:nth-child(12)', el => el.textContent.trim()).catch(() => '');

        // Verificar si el estado está en la lista de exclusión
        if (excluirEstados.length > 0 && excluirEstados.includes(estadoRadian.toLowerCase())) {
          skipped++;
          logger.emit('log', `⏭️ Fila ${i + 1} omitida — Estado RADIAN: ${estadoRadian}`);
          continue;
        }

        const btn = await row.$('button');
        if (btn) {
          downloadable++;
          logger.emit('log', `⬇️ Descargando archivo ${downloadable}... (${estadoRadian || 'Sin estado'})`);
          // Huella de la fila: si aparece la vista anti-bot, al volver verificamos
          // que el índice siga apuntando a esta misma fila (detecta un reset de
          // paginado que duplicaría/omitiría descargas).
          const filaKey = await leerFilaKey(row);
          const beforeCount = fs.readdirSync(downloadPath).filter(f => f.toLowerCase().endsWith('.zip')).length;

          // El click puede DISPARAR la navegación a la vista anti-bot; en ese caso
          // ElementHandle.click() rechaza con "Execution context destroyed". Es
          // ESPERADO: lo tragamos para que la recuperación de abajo SIEMPRE corra
          // (si no, se saltearía el goBack y se abortaría el resto de la página).
          try {
            await btn.click();
          } catch (e) {
            // Solo tragamos errores típicos de "navegó mientras clickeaba"; otros
            // (botón realmente no clickeable, etc.) deben propagar.
            if (!/context was destroyed|detached|Target closed|Session closed/i.test(e.message || '')) throw e;
          }

          const ok = await waitForDownload(downloadPath, beforeCount, 30000);
          if (ok) {
            totalDocuments++; // contar SOLO descargas confirmadas (no clicks)
          } else {
            logger.emit('log', `⚠️ Descarga ${downloadable} no completó en 30s (continuando)`);
          }

          // Pausa corta (+ jitter): da tiempo a que, si la DIAN va a redirigir, la
          // navegación a la vista anti-bot termine antes de comprobar dónde quedamos.
          await pausaAntibloqueo(DELAY_DESCARGA_MS);

          // Si redirigió a la vista anti-bot, el .zip ya bajó pero la página quedó
          // atrapada: volver al listado con un 'Atrás'. En descargas que no
          // navegan, es no-op.
          const rec = await volverAlListadoSiHaceFalta(page, { currentPage });
          if (!rec.ok) {
            throw new Error('Sesión DIAN perdida tras la vista anti-bot — no se pudo volver al listado. Resultado parcial.');
          }
          // Si hubo que volver (navegó), exigir confirmación POSITIVA de que el
          // índice sigue en la misma fila. Si la huella no se puede confirmar
          // (nula o distinta), abortar: la DIAN pudo resetear el paginado y seguir
          // duplicaría u omitiría descargas.
          if (rec.navego) {
            const filasAhora = await page.$$(FILA_REAL);
            const keyAhora = i < filasAhora.length ? await leerFilaKey(filasAhora[i]) : null;
            if (!filaKey || !keyAhora || keyAhora !== filaKey) {
              throw new Error(`Desalineación tras la vista anti-bot: no se pudo confirmar que la fila ${i + 1} siga en su lugar al volver (la DIAN pudo resetear el paginado). Abortando para no duplicar ni omitir descargas. Resultado parcial.`);
            }
          }

          // Espera ~90 s entre líneas (requisito de la DIAN; aplica también antes
          // del salto de página). El jitter evita un ritmo robótico.
          logger.emit('log', `⏳ Esperando ${Math.round(DELAY_LINEA_MS / 1000)}s antes de continuar…`);
          await pausaAntibloqueo(DELAY_LINEA_MS);
        }
      } catch (err) {
        logger.emit('log', `⚠️ Error al descargar fila ${i + 1}: ${err.message}`);
        // Errores fatales (sesión perdida / desalineación): abortar la corrida en
        // vez de seguir contra filas equivocadas. El catch externo marca
        // exitCode=1 y el Excel se rotula parcial.
        if (/Resultado parcial/i.test(err.message || '')) throw err;
      }
    }

    if (skipped > 0) {
      logger.emit('log', `📊 Resumen página ${currentPage}: ${downloadable} descargados, ${skipped} omitidos por estado RADIAN`);
    } else {
      logger.emit('log', `➡️ Descargados ${downloadable} de ${rowCount} filas`);
    }

    // Verificar si existe el botón "Siguiente" habilitado
    const nextBtn = await page.$('button[data-dt-idx="next"]');
    const isDisabled = !nextBtn || await page.evaluate(el => el.classList.contains('disabled') || el.getAttribute('aria-disabled') === 'true', nextBtn);

    if (isDisabled) {
      hasNextPage = false;
      logger.emit('log', '✅ Fin del paginado');
    } else {
      logger.emit('log', '➡️ Siguiente página...');
      await nextBtn.click();
      await pausaAntibloqueo(DELAY_PAGINA_MS);
      currentPage++;
    }
  }

  if (mode !== 'cufes') {
    logger.emit('log', `✅ Descargas completadas: ${totalDocuments} archivos descargados`);
  }
  } catch (error) {
    exitCode = 1;
    logger.emit('log', '🔴 Error durante la extracción');
    logger.emit('log', error.message || error.toString());
  } finally {
    // Cerrar browser antes de procesar ZIPs (libera memoria/locks).
    // Con timeout: si Chrome quedó colgado (p.ej. tras un reCAPTCHA), no esperar
    // indefinidamente al cierre — eso dejaría el proceso vivo ocupando un slot.
    try {
      if (browser) await Promise.race([
        browser.close(),
        new Promise(r => setTimeout(r, 10000)),
      ]);
    } catch (_) {}

    // Modo CUFE: escribir el resultado (JSON + Excel) con los CUFEs recolectados.
    if (mode === 'cufes') {
      try {
        logger.emit('log', `➡️ Generando resultado con ${cufes.length} CUFE(s)...`);
        await writeCufesResult(cufes, downloadPath);
      } catch (e) {
        logger.emit('log', `❌ Error generando resultado de CUFEs: ${e.message}`);
        exitCode = exitCode || 1;
      }
    }

    // Siempre intentar consolidar Excel/JSON con lo que se haya descargado,
    // incluso si la sesión DIAN expiró a mitad de paginado.
    try {
      const zipsOnDisk = fs.existsSync(downloadPath)
        ? fs.readdirSync(downloadPath).filter(f => f.toLowerCase().endsWith('.zip')).length
        : 0;
      if (zipsOnDisk > 0) {
        const label = exitCode === 0 ? '' : ' (parcial)';
        logger.emit('log', `➡️ Generando Excel${label} con ${zipsOnDisk} ZIPs en disco...`);
        await processFolder(downloadPath, tipo);
      }
    } catch (e) {
      logger.emit('log', `❌ Error generando Excel: ${e.message}`);
      exitCode = exitCode || 1;
    }

    logger.emit('log', '✅ Proceso terminado');
    process.exit(exitCode);
  }
})();