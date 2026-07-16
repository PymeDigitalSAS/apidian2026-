// Helpers compartidos del listado de documentos del portal DIAN (DataTables),
// usados por index.js (método token/URL) e index-certificate.js (método
// certificado). Se centralizan ACÁ para que ambos flujos no vuelvan a divergir.
//
// MOTIVO: la DIAN ahora, al pulsar "descargar" en una fila, puede REDIRIGIR a una
// vista anti-bot ("identificá que no sos un robot"). El .zip baja igual, pero la
// página queda atrapada en esa vista y hay que retroceder (goBack) al listado.
// Estas funciones detectan ese caso y vuelven, de forma robusta.

const logger = require('./logger');

// DataTables, sin datos o durante una recarga AJAX, inyecta una fila placeholder
// <tr class="dataTables_empty">. La excluimos para no confundirla con filas reales.
const FILA_REAL = '#tableDocuments tbody tr:not(.dataTables_empty)';

// ¿Seguimos en el listado de documentos (hay al menos una fila real)?
async function enListado(page) {
  return (await page.$(FILA_REAL)) !== null;
}

// Espera a que el listado quede listo otra vez tras una recarga (p. ej. después
// de un goBack): primero a que el loader de DataTables APAREZCA (la recarga AJAX
// arrancó) y luego a que DESAPAREZCA, y por último a que haya una fila real.
// Emite un heartbeat (DEBUG_ONLY) para resetear el watchdog de inactividad del
// server durante la espera.
async function esperarListadoListo(page, { timeout = 45000 } = {}) {
  // Fase 1: el loader aparece (la recarga arrancó). Opcional: puede no recargar.
  await page.waitForFunction(() => {
    const el = document.querySelector('#tableDocuments_processing');
    return el && el.style.display !== 'none' && el.style.display !== '';
  }, { timeout: 6000 }).catch(() => {});
  logger.emit('log', 'DEBUG_ONLY:↩️ esperando que el listado recargue…'); // heartbeat watchdog
  // Fase 2: el loader desaparece (la recarga terminó).
  await page.waitForFunction(() => {
    const el = document.querySelector('#tableDocuments_processing');
    return !el || el.style.display === 'none' || el.style.display === '';
  }, { timeout }).catch(() => {});
  // Fase 3: hay una fila real renderizada.
  await page.waitForSelector(FILA_REAL, { timeout }).catch(() => {});
}

// Huella estable de una fila (texto de TODAS sus columnas) para detectar si, tras
// volver del anti-bot, el índice sigue apuntando a la MISMA fila lógica. Usamos
// todas las celdas (no solo las primeras) para no colisionar entre facturas que
// comparten emisor/fecha/tipo pero difieren en folio/CUFE en columnas posteriores.
async function leerFilaKey(rowHandle) {
  return await rowHandle.evaluate(tr =>
    Array.from(tr.querySelectorAll('td')).map(td => td.textContent.trim()).join('|')
  ).catch(() => null);
}

// Espera a que un challenge de Cloudflare/WAF (el 'Atrás' puede caer en uno) se
// resuelva. Self-contained acá para que AMBOS flujos (token y certificado) tengan
// el MISMO comportamiento sin depender de una función externa.
async function esperarCloudflare(page, maxMs = 30000) {
  const inicio = Date.now();
  while (true) {
    const challenge = page.frames().some(f => (f.url() || '').includes('challenges.cloudflare.com'));
    const title = await page.title().catch(() => '');
    const enDesafio = challenge || /just a moment|checking your browser|un momento|verificando|azure waf|waf/i.test(title);
    if (!enDesafio) return;
    if (Date.now() - inicio > maxMs) return;
    await new Promise(r => setTimeout(r, 500));
  }
}

// Si una descarga disparó la vista anti-bot, vuelve al listado con un solo
// 'Atrás' (el filtro de fechas y la página del paginado se conservan). Devuelve
// { ok, navego }:
//   • navego=false → seguíamos en el listado (la descarga no navegó): no-op.
//   • ok=false     → no se pudo recuperar el listado (sesión probablemente perdida).
// Internamente espera un eventual challenge de Cloudflare por si el 'Atrás' cae
// en uno (igual para ambos flujos, token y certificado).
async function volverAlListadoSiHaceFalta(page, { currentPage = 1 } = {}) {
  if (await enListado(page)) return { ok: true, navego: false };
  logger.emit('log', '🤖 Vista anti-bot de la DIAN detectada — volviendo al listado…');
  if (currentPage > 1) {
    logger.emit('log', `⚠️ La verificación apareció en la página ${currentPage} del paginado: al volver, la DIAN podría resetear a la página 1.`);
  }
  try {
    await page.goBack({ waitUntil: 'domcontentloaded', timeout: 45000 });
  } catch (_) { /* el goBack tras una descarga puede resolver raro; seguimos */ }
  await esperarCloudflare(page).catch(() => {});   // por si el 'Atrás' cae en un challenge CF
  await esperarListadoListo(page);
  const ok = await enListado(page);
  if (!ok) logger.emit('log', '⚠️ No se pudo volver al listado tras la vista anti-bot (la sesión pudo haberse perdido).');
  return { ok, navego: true };
}

module.exports = { FILA_REAL, enListado, esperarListadoListo, leerFilaKey, volverAlListadoSiHaceFalta };
