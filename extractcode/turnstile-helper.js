// Helper aislado para Cloudflare Turnstile del flujo de certificado de DIAN.
//
// CONTEXTO:
//   DIAN usa Cloudflare Turnstile en el form de /User/CertificateLogin. En Windows
//   local con IP residencial Turnstile auto-resuelve sin intervención y el token
//   cf-turnstile-response se llena solo. En VPS / datacenter IPs Cloudflare
//   responde con un iframe titulado "Checking your Browser…" y body vacío —
//   nunca renderiza el checkbox ni emite el token (deny silencioso por
//   fingerprint/IP).
//
// USO:
//   const { tryClickTurnstile, waitForTurnstileToken } = require('./turnstile-helper');
//
//   // Después de detectar el form y esperar a que cargue
//   await tryClickTurnstile(page);
//
//   // Antes de submit, validar que Turnstile haya emitido token
//   const r = await waitForTurnstileToken(page, 30000);
//   if (r.status === 'empty') throw new Error('Turnstile no generó token');
//
// NOTA: si en el futuro se consigue bypass (proxy residencial, etc.), todo
// lo relacionado vive ACÁ. `index-certificate.js` solo invoca este módulo.

/**
 * Intenta clickear el checkbox del widget Turnstile cuando es interactivo.
 * En Windows (Cloudflare confía en el IP) suele auto-resolver — esta función
 * detecta ese caso y retorna inmediatamente. En VPS bloqueado intenta el
 * click pero suele fallar porque el iframe nunca renderiza la UI.
 *
 * No logguea por defecto (silencioso). Pasá un `logger` con .emit('log', ...)
 * para diagnóstico verboso.
 *
 * @returns {Promise<string>} estado: 'already-resolved' | 'clicked' | 'no-iframe' | 'no-clickable' | 'silent-deny' | 'error'
 */
async function tryClickTurnstile(page, logger = null) {
  const log = (msg) => { if (logger && logger.emit) logger.emit('log', msg); };
  try {
    // 1) ¿Ya está resuelto? (caso Windows con IP residencial)
    const tokenYa = await page.evaluate(() => {
      const el = document.querySelector('[name="cf-turnstile-response"]');
      return !!(el && el.value && el.value.length > 20);
    });
    if (tokenYa) {
      log(`✅ Turnstile auto-resolvió (no requiere click)`);
      return 'already-resolved';
    }

    // 2) Buscar el iframe del challenge (puede tardar unos segundos)
    const iframeWaitStart = Date.now();
    let frame = null;
    while (Date.now() - iframeWaitStart < 8000 && !frame) {
      frame = page.frames().find(f => {
        const u = f.url() || '';
        return u.includes('challenges.cloudflare.com');
      });
      if (frame) break;
      await new Promise(r => setTimeout(r, 500));
    }
    if (!frame) {
      log(`ℹ️ Turnstile: no se detectó iframe del challenge`);
      return 'no-iframe';
    }

    // 3) Dar tiempo a Cloudflare para pintar el contenido del iframe
    await new Promise(r => setTimeout(r, 3000));

    // 4) Detectar "deny silencioso": iframe con título "Checking your Browser…"
    //    y body vacío significa que Cloudflare decidió no emitir token por
    //    fingerprint/IP. No vale la pena intentar click.
    const iframeTitle = await frame.evaluate(() => document.title || '').catch(() => '');
    const iframeHasUI = await frame.evaluate(() => {
      return !!(
        document.querySelector('input[type="checkbox"]') ||
        document.querySelector('label') ||
        document.querySelector('button') ||
        document.querySelector('[role="checkbox"]')
      );
    }).catch(() => false);
    if (/checking your browser/i.test(iframeTitle) && !iframeHasUI) {
      log(`⚠️ Turnstile en "deny silencioso" (título="${iframeTitle}", sin UI) — Cloudflare no emitirá token desde este entorno`);
      return 'silent-deny';
    }

    // 5) Intentar click con varios selectores
    const candidates = ['input[type="checkbox"]', 'label', '[role="checkbox"]', 'button'];
    for (const sel of candidates) {
      try {
        const el = await frame.waitForSelector(sel, { timeout: 2000 });
        if (el) {
          await el.click();
          log(`🖱️ Turnstile checkbox clickeado (selector="${sel}")`);
          // Esperar a que Cloudflare procese el click
          await new Promise(r => setTimeout(r, 5000));
          return 'clicked';
        }
      } catch (_) { /* siguiente candidato */ }
    }
    log(`⚠️ Turnstile: iframe presente pero ningún elemento clickeable`);
    return 'no-clickable';
  } catch (err) {
    log(`ℹ️ Turnstile helper falló: ${err.message.split('\n')[0]}`);
    return 'error';
  }
}

/**
 * Espera a que Cloudflare Turnstile emita el token en el campo
 * cf-turnstile-response. Útil para gatear el submit del form y evitar
 * la respuesta "Recaptcha inválido" de DIAN.
 *
 * @returns {Promise<{status: 'ready'|'empty'|'no-field', length: number}>}
 */
async function waitForTurnstileToken(page, timeoutMs = 30000) {
  const hasField = await page.$('input[name="cf-turnstile-response"]');
  if (!hasField) return { status: 'no-field', length: 0 };

  try {
    await page.waitForFunction(() => {
      const input = document.querySelector('input[name="cf-turnstile-response"]');
      return !!(input && input.value && input.value.length > 20);
    }, { timeout: timeoutMs });
  } catch (_) {
    // Se reporta abajo con la longitud actual.
  }

  const length = await page.evaluate(() => {
    const input = document.querySelector('input[name="cf-turnstile-response"]');
    return input && input.value ? input.value.length : 0;
  }).catch(() => 0);

  return { status: length > 20 ? 'ready' : 'empty', length };
}

module.exports = { tryClickTurnstile, waitForTurnstileToken };
