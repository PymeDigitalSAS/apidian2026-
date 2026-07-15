// Carga variables de entorno desde un archivo .env (si existe) en la raíz del proyecto.
// No sobreescribe variables ya definidas en el entorno: Docker/PM2/terminal tienen
// prioridad sobre el .env. Ver .env.example para la lista completa.
// Envuelto en try/catch para que el server arranque igual si dotenv NO está instalado
// (p. ej. se actualizó con `git pull` sin correr `npm install`): en ese caso simplemente
// se usan las variables del sistema y los valores por defecto.
try {
  require('dotenv').config({ path: require('path').join(__dirname, '.env') });
} catch (_) {
  console.warn('⚠️ dotenv no está instalado — usando variables del sistema y defaults. Ejecutá "npm install" para soportar archivos .env.');
}

// Si no se cargó el legacy provider de OpenSSL (necesario para certs DIAN con
// algoritmos legados), re-ejecutar este mismo script con el flag correcto.
const _nodeOpts = (process.env.NODE_OPTIONS || '') + process.execArgv.join(' ');
if (!_nodeOpts.includes('--openssl-legacy-provider')) {
  const { spawnSync } = require('child_process');
  const result = spawnSync(
    process.execPath,
    ['--openssl-legacy-provider', ...process.argv.slice(1)],
    { stdio: 'inherit', env: process.env }
  );
  process.exit(result.status ?? 0);
}

const express = require('express');
const path = require('path');
const { spawn, exec } = require('child_process');
const app = express();
const axios = require('axios');
const fs = require('fs');
const fsp = require('fs').promises;
const os = require('os');
const net = require('net');
const crypto = require('crypto');
const https = require('https');

let CHROME_PATH;
let PROFILES_ROOT;
let IS_HEADLESS = false;

const platform = os.platform();

if (platform === 'win32') {
  CHROME_PATH = `"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe"`;
  PROFILES_ROOT = path.join(os.tmpdir(), 'chrome-profiles');
} else if (platform === 'linux') {
  // En Linux preferimos Google Chrome del sistema sobre Chrome for Testing de
  // Puppeteer. Razón: Chrome for Testing ignora enterprise policies por diseño,
  // y nuestra extracción con certificado p12 depende de AutoSelectCertificateForUrls
  // para que Chrome no cuelgue pidiendo el cert en cada request mTLS.
  const systemChromes = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
  ];
  CHROME_PATH = systemChromes.find(p => fs.existsSync(p));
  if (!CHROME_PATH) {
    // Fallback a Chrome for Testing si no hay Chrome del sistema — funciona para
    // el modo URL pero NO para el modo certificado.
    try {
      const puppeteer = require('puppeteer');
      CHROME_PATH = puppeteer.executablePath();
      console.warn('⚠️ Usando Chrome for Testing (Puppeteer). El modo certificado NO funcionará — instalá google-chrome-stable.');
    } catch (e) {
      CHROME_PATH = '/usr/bin/google-chrome';
    }
  }
  PROFILES_ROOT = path.join(os.tmpdir(), 'chrome-profiles');
  IS_HEADLESS = !['0', 'false', 'no'].includes(String(process.env.CHROME_HEADLESS || 'true').toLowerCase());
} else if (platform === 'darwin') {
  CHROME_PATH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  PROFILES_ROOT = path.join(os.tmpdir(), 'chrome-profiles');
} else {
  console.error(`Sistema operativo no soportado: ${platform}`);
}

if (!fs.existsSync(PROFILES_ROOT)) fs.mkdirSync(PROFILES_ROOT, { recursive: true });

// Perfil base con el certificado DIAN ya instalado.
// Si existe, se copia al inicio de cada sesión para que Chrome tenga el certificado.
// Configurable por env o usa el perfil legacy de la instalación anterior.
const BASE_PROFILE_DIR = process.env.CHROME_BASE_PROFILE || (
  platform === 'win32' ? 'C:\\chrome-profile' : path.join(os.homedir(), 'chrome-profile')
);

// Perfil base DEDICADO al modo token (Modo 1), separado del de certificado.
// Limpio (sin cert ni extensiones) → Cloudflare lo ve como un Chrome normal.
// Persistente entre corridas → conserva la cookie de clearance de Cloudflare,
// que es exactamente lo que hace que funcione en el VPS Docker.
const TOKEN_PROFILE_DIR = process.env.CHROME_TOKEN_PROFILE || (
  platform === 'win32' ? 'C:\\chrome-profile-token' : path.join(os.homedir(), 'chrome-profile-token')
);

console.log(`Chrome Path: ${CHROME_PATH}`);
console.log(`Profiles Root: ${PROFILES_ROOT}`);
console.log(`Base Profile: ${BASE_PROFILE_DIR} (${fs.existsSync(BASE_PROFILE_DIR) ? 'encontrado' : 'no encontrado — sesiones sin certificado'})`);

// En Linux verificamos que las dependencias del modo certificado estén presentes,
// así el problema se ve al arrancar y no en la primera request.
if (platform === 'linux') {
  const pk12 = ['/usr/bin/pk12util', '/usr/local/bin/pk12util'].find(p => fs.existsSync(p));
  const cu = ['/usr/bin/certutil', '/usr/local/bin/certutil'].find(p => fs.existsSync(p));
  if (!pk12 || !cu) {
    console.warn(`⚠️ NSS tools faltan (pk12util=${!!pk12}, certutil=${!!cu}). Modo certificado NO funcionará.`);
    console.warn(`   Solución: en el contenedor → apt-get install -y libnss3-tools`);
    console.warn(`   O reconstruir: docker-compose build --no-cache extractor`);
  } else {
    console.log(`NSS tools: ${pk12} ✓ | ${cu} ✓`);
  }
  // Verificar permisos en /etc para la policy de Chrome
  try {
    fs.accessSync('/etc/opt/chrome/policies/managed', fs.constants.W_OK);
    console.log('Policy /etc/opt/chrome/policies/managed: escribible ✓');
  } catch (_) {
    try {
      fs.mkdirSync('/etc/opt/chrome/policies/managed', { recursive: true });
      console.log('Policy /etc/opt/chrome/policies/managed: creado ✓');
    } catch (err) {
      console.warn(`⚠️ No se puede escribir en /etc/opt/chrome/policies/managed (${err.code}). Modo certificado puede colgar pidiendo cert.`);
    }
  }
}

// Límite de Chrome concurrentes. Cada Chrome cuesta ~200-400 MB RAM.
// Las peticiones que excedan el límite quedan en cola FIFO.
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT_SESSIONS, 10) || 3;

// Límite generoso: el p12 en base64 y las listas de CUFEs (paso 2 / by-cufes-list)
// pueden superar el default de 100kb de Express.
app.use(express.urlencoded({ extended: true, limit: '25mb' }));
app.use(express.static(path.join(__dirname, 'public')));
app.use(express.json({ limit: '25mb' }));

// ============================================================
// Autenticación por API Key
// ============================================================
// Configura API_SECRET_KEY en el entorno. El proyecto que consuma
// esta API debe enviar el mismo valor en el header X-Api-Key.
// Si la variable no está definida, se permite el acceso (modo sin clave).
let _apiKeyWarningLogged = false;
function apiKeyAuth(req, res, next) {
  const secret = process.env.API_SECRET_KEY;
  if (!secret) {
    if (!_apiKeyWarningLogged) {
      console.warn('[auth] API_SECRET_KEY no configurada — endpoints /api/* son públicos');
      _apiKeyWarningLogged = true;
    }
    return next();
  }
  const provided =
    req.headers['x-api-key'] ||
    (req.headers['authorization'] || '').replace(/^Bearer\s+/i, '');
  if (!provided || provided !== secret) {
    return res.status(401).json({ success: false, message: 'No autorizado' });
  }
  next();
}
app.use('/api', apiKeyAuth);

// ============================================================
// Helpers de sesión
// ============================================================

function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const port = srv.address().port;
      srv.close(() => resolve(port));
    });
  });
}

async function copyDir(src, dest) {
  await fsp.mkdir(dest, { recursive: true });
  const entries = await fsp.readdir(src, { withFileTypes: true });
  await Promise.all(entries.map(async (entry) => {
    const srcPath = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);
    if (entry.isDirectory()) {
      await copyDir(srcPath, destPath);
    } else {
      try {
        await fsp.copyFile(srcPath, destPath);
      } catch (err) {
        // Archivos bloqueados por Chrome en uso (EBUSY/EPERM) — se omiten.
        // Chrome los recrea al iniciar con el perfil copiado.
        if (err.code !== 'EBUSY' && err.code !== 'EPERM') throw err;
      }
    }
  }));
}

// Archivos de lock que Chrome crea en el perfil y que deben eliminarse
// antes de abrir el perfil copiado, para que Chrome no lo considere en uso.
const CHROME_LOCK_FILES = ['SingletonLock', 'SingletonCookie', 'SingletonSocket', 'LOCK'];

function buildSessionLabel(tipo, fechaDesde, fechaHasta, userCode) {
  const tz = process.env.TZ || 'America/Bogota';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    hour12: false,
  }).formatToParts(new Date()).reduce((acc, p) => { acc[p.type] = p.value; return acc; }, {});
  const ts = `${parts.year}-${parts.month}-${parts.day}_${parts.hour}-${parts.minute}-${parts.second}`;
  // Sufijo aleatorio para evitar colisiones cuando dos requests entran en el mismo segundo
  // con userCode/tipo/fechas idénticos (ej. Laravel reintentando).
  const suffix = crypto.randomBytes(3).toString('hex');
  return [userCode, tipo, fechaDesde, fechaHasta, ts, suffix].filter(Boolean).join('_');
}

// Sentinel que marca una carpeta como "extracción en curso" para que el
// cleanup automático no la borre aunque su mtime sea viejo.
const INPROGRESS_FILE = '.in-progress';
async function markInProgress(downloadPath) {
  try { await fsp.writeFile(path.join(downloadPath, INPROGRESS_FILE), String(Date.now()), 'utf8'); } catch (_) {}
}
async function clearInProgress(downloadPath) {
  try { await fsp.unlink(path.join(downloadPath, INPROGRESS_FILE)); } catch (_) {}
}

async function createSession(label, { useBaseProfile = true, baseProfileDir = BASE_PROFILE_DIR } = {}) {
  const sessionId = crypto.randomUUID();
  const userDataDir = path.join(PROFILES_ROOT, sessionId);
  const folderName = label || sessionId;
  const downloadPath = path.resolve(__dirname, 'downloads', folderName);
  const port = await getFreePort();

  const baseDefault = path.join(baseProfileDir, 'Default');
  const sessionDefault = path.join(userDataDir, 'Default');

  if (useBaseProfile && fs.existsSync(baseDefault)) {
    await copyDir(baseDefault, sessionDefault);
    // Eliminar lock files del perfil copiado para que Chrome no piense
    // que el perfil está en uso por otra instancia.
    for (const lockFile of CHROME_LOCK_FILES) {
      for (const base of [userDataDir, sessionDefault]) {
        try { await fsp.unlink(path.join(base, lockFile)); } catch (_) {}
      }
    }
  } else {
    await fsp.mkdir(userDataDir, { recursive: true });
  }

  await fsp.mkdir(downloadPath, { recursive: true });
  await markInProgress(downloadPath);
  return { sessionId, userDataDir, downloadPath, port };
}

// Mutex para que solo una sesión a la vez actualice el perfil base.
let profileSaveInProgress = false;

async function saveProfileToBase(userDataDir, baseProfileDir = BASE_PROFILE_DIR) {
  if (profileSaveInProgress) return;
  profileSaveInProgress = true;
  try {
    const sessionDefault = path.join(userDataDir, 'Default');
    const baseDefault = path.join(baseProfileDir, 'Default');
    if (!fs.existsSync(sessionDefault)) return;
    await fsp.mkdir(baseProfileDir, { recursive: true });
    await copyDir(sessionDefault, baseDefault);
    // Limpiar lock files del perfil base recién guardado.
    for (const lockFile of CHROME_LOCK_FILES) {
      for (const base of [baseProfileDir, baseDefault]) {
        try { await fsp.unlink(path.join(base, lockFile)); } catch (_) {}
      }
    }
    console.log('💾 Perfil base actualizado con la sesión autenticada');
  } catch (err) {
    console.warn('⚠️ No se pudo guardar el perfil base:', err.message);
  } finally {
    profileSaveInProgress = false;
  }
}

// Mata Chrome de forma robusta. En Windows `exec` lanza cmd.exe → chrome.exe;
// un kill normal mata solo el cmd.exe y deja Chrome huérfano (ventana abierta +
// lock del perfil). taskkill /T /F mata el árbol completo. Clave cuando el
// watchdog aborta y el scraper no alcanzó a hacer browser.close().
function killChromeTree(proc) {
  if (!proc || proc.pid == null) return;
  try {
    if (platform === 'win32') {
      spawn('taskkill', ['/pid', String(proc.pid), '/T', '/F'], { stdio: 'ignore' });
    } else {
      try { proc.kill('SIGKILL'); } catch (_) {}
    }
  } catch (_) {}
}

async function cleanupSession(session, chromeProcess, { saveProfile = true, baseProfileDir = BASE_PROFILE_DIR } = {}) {
  // 1. Matar Chrome (árbol completo) para que cierre la ventana y suelte el
  //    perfil, incluso si el scraper fue abortado sin hacer browser.close().
  killChromeTree(chromeProcess);

  // 2. Esperar que Chrome libere los archivos del perfil.
  await new Promise(r => setTimeout(r, 2000));

  // 3. Guardar el perfil actualizado de vuelta al base DESPUÉS de que
  //    Chrome cerró (garantiza que las cookies y sesión estén en disco).
  //    El modo token usa perfil limpio y NO debe pisar el perfil base
  //    (que es el del certificado) — por eso saveProfile=false en ese caso.
  if (saveProfile) {
    await saveProfileToBase(session.userDataDir, baseProfileDir);
  }

  // 4. Borrar el directorio temporal con reintentos por locks residuales en Windows.
  for (let attempt = 1; attempt <= 10; attempt++) {
    await new Promise(r => setTimeout(r, attempt * 500));
    try {
      await fsp.rm(session.userDataDir, { recursive: true, force: true });
      return;
    } catch (err) {
      if (attempt === 10) {
        console.warn(`⚠️ No se pudo borrar perfil ${session.userDataDir}: ${err.message}`);
      }
    }
  }
}

// ============================================================
// Manejo del certificado p12 en el store del SO (solo Windows)
// Importa el cert temporalmente para que Chrome lo auto-seleccione
// sin mostrar el diálogo nativo de TLS.
// ============================================================

function runPowerShell(script) {
  // -EncodedCommand evita problemas de escape en la línea de comandos.
  const encoded = Buffer.from(script, 'utf16le').toString('base64');
  return new Promise((resolve, reject) => {
    exec(`powershell.exe -NonInteractive -NoProfile -EncodedCommand ${encoded}`, (err, stdout, stderr) => {
      // PowerShell en su primer arranque emite un registro CLIXML "progress"
      // ("Preparando módulos para el primer uso") en stderr. No es un error real:
      // si stderr SOLO contiene ese CLIXML progress, ignorar y devolver stdout.
      const stderrText = (stderr || '').trim();
      const isJustCLIXMLProgress = stderrText.startsWith('#< CLIXML') &&
        /S="progress"/.test(stderrText) &&
        !/S="error"/i.test(stderrText);
      if (err && !isJustCLIXMLProgress) return reject(new Error(stderrText || err.message));
      resolve(stdout.trim());
    });
  });
}

async function installCertForChrome(pfxBuffer, p12Password, hostname, log = () => {}) {
  const tmpP12 = path.join(os.tmpdir(), `dian_${crypto.randomUUID()}.pfx`);
  await fsp.writeFile(tmpP12, pfxBuffer);

  const psPwd  = p12Password.replace(/'/g, "''");
  const psPath = tmpP12.replace(/\\/g, '\\\\');

  // 1. Leer thumbprint y subject del p12 SIN importarlo todavía
  const psInspect = `
$ErrorActionPreference = 'Stop'
$bytes = [System.IO.File]::ReadAllBytes('${psPath}')
$cert  = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($bytes, '${psPwd}')
Write-Output "$($cert.Thumbprint)|$($cert.Subject)"
`;
  let thumbprint, subject;
  try {
    const info = (await runPowerShell(psInspect)).split(/\r?\n/).filter(Boolean).pop();
    [thumbprint, ...subjectParts] = info.split('|');
    subject = subjectParts.join('|').trim();
    thumbprint = thumbprint.trim();
  } catch (err) {
    await fsp.unlink(tmpP12).catch(() => {});
    throw new Error(`No se pudo leer el certificado p12: ${err.message}`);
  }

  // 2. Verificar si el cert ya estaba instalado antes de nuestra ejecución
  const psCheck = `if (Test-Path "Cert:\\CurrentUser\\My\\${thumbprint}") { 'exists' } else { 'new' }`;
  const checkResult = (await runPowerShell(psCheck)).trim();
  const alreadyInstalled = checkResult === 'exists';

  // 3. Importar solo si no estaba ya
  if (!alreadyInstalled) {
    const psImport = `
$ErrorActionPreference = 'Stop'
$pwd = ConvertTo-SecureString '${psPwd}' -AsPlainText -Force
Import-PfxCertificate -FilePath '${psPath}' -CertStoreLocation Cert:\\CurrentUser\\My -Password $pwd | Out-Null
`;
    await runPowerShell(psImport);
    log(`🔐 Certificado importado al store de Windows (${thumbprint.slice(0, 8)}...)`);
  } else {
    log(`🔐 Certificado ya presente en el store de Windows (${thumbprint.slice(0, 8)}...)`);
  }

  await fsp.unlink(tmpP12).catch(() => {});

  // 4. Extraer CN del subject para filtrar específicamente ese cert
  const cnMatch = subject.match(/CN=([^,]+)/);
  const subjectCN = cnMatch ? cnMatch[1].trim() : '';
  const filter = subjectCN
    ? `{"pattern":"https://${hostname}","filter":{"SUBJECT":{"CN":"${subjectCN}"}}}`
    : `{"pattern":"https://${hostname}","filter":{}}`;

  // 5. Política de Chrome: auto-seleccionar ESTE cert específico para el dominio DIAN
  const psReg = `
$ErrorActionPreference = 'Stop'
$regPath = 'HKCU:\\SOFTWARE\\Policies\\Google\\Chrome\\AutoSelectCertificateForUrls'
if (-not (Test-Path $regPath)) { New-Item -Path $regPath -Force | Out-Null }
Set-ItemProperty -Path $regPath -Name '1' -Value '${filter.replace(/'/g, "''")}' -Type String -Force
`;
  await runPowerShell(psReg);
  log(`🔐 Chrome auto-seleccionará: CN="${subjectCN || '*'}" para https://${hostname}`);

  return { thumbprint, alreadyInstalled };
}

async function uninstallCertForChrome({ thumbprint, alreadyInstalled }) {
  // Solo borrar el cert si lo importamos nosotros — nunca borrar uno preexistente
  const removeCert = alreadyInstalled
    ? ''
    : `Remove-Item -Path "Cert:\\CurrentUser\\My\\${thumbprint}" -ErrorAction SilentlyContinue`;
  const psClean = `
$ErrorActionPreference = 'SilentlyContinue'
${removeCert}
Remove-ItemProperty -Path 'HKCU:\\SOFTWARE\\Policies\\Google\\Chrome\\AutoSelectCertificateForUrls' -Name '1'
`;
  await runPowerShell(psClean).catch(err =>
    console.warn('⚠️ No se pudo limpiar la política de Chrome:', err.message)
  );
}

// ============================================================
// Manejo del certificado p12 en NSS database (Linux/headless)
// Chrome en Linux usa ~/.pki/nssdb para certificados de cliente.
// Requiere libnss3-tools (pk12util, certutil).
// ============================================================

function execFileAsync(bin, args) {
  return new Promise((resolve, reject) => {
    const { execFile } = require('child_process');
    execFile(bin, args, (err, stdout, stderr) => {
      if (err) return reject(new Error((stderr || '').trim() || err.message));
      resolve(stdout.trim());
    });
  });
}

async function installCertForChromeLinux(pfxBuffer, p12Password, log = () => {}) {
  const nssdb = path.join(os.homedir(), '.pki', 'nssdb');
  await fsp.mkdir(nssdb, { recursive: true });

  // Usar rutas absolutas para evitar ENOENT si PATH no incluye /usr/bin
  // (pasa con algunos managers de procesos en containers).
  const certutilBin = ['/usr/bin/certutil', '/usr/local/bin/certutil'].find(p => fs.existsSync(p));
  const pk12utilBin = ['/usr/bin/pk12util', '/usr/local/bin/pk12util'].find(p => fs.existsSync(p));
  if (!certutilBin || !pk12utilBin) {
    throw new Error(
      `NSS tools no encontrados (certutil=${!!certutilBin}, pk12util=${!!pk12utilBin}). ` +
      `Instalá libnss3-tools en el contenedor: 'apt-get install -y libnss3-tools'. ` +
      `Si reconstruiste el Dockerfile, asegurate de usar 'docker-compose build --no-cache'.`
    );
  }

  // Inicializar NSS db si no existe (--empty-password es seguro en entorno servidor)
  await execFileAsync(certutilBin, ['-N', '--empty-password', '-d', `sql:${nssdb}`]).catch(() => {});

  const tmpP12 = path.join(os.tmpdir(), `dian_${crypto.randomUUID()}.p12`);
  const tmpPwd = path.join(os.tmpdir(), `dian_${crypto.randomUUID()}.pwd`);
  await fsp.writeFile(tmpP12, pfxBuffer);
  await fsp.writeFile(tmpPwd, p12Password, 'utf8');

  const nickname = `dian_cert_${Date.now()}`;
  try {
    // -w lee la contraseña desde archivo — evita shell injection y problemas de caracteres especiales
    await execFileAsync(pk12utilBin, ['-i', tmpP12, '-d', `sql:${nssdb}`, '-w', tmpPwd, '-n', nickname]);
    log(`🔐 Certificado instalado en NSS (${nickname}) — Chrome headless lo presentará automáticamente`);
  } catch (err) {
    throw new Error(`No se pudo instalar el certificado en NSS: ${err.message}`);
  } finally {
    await fsp.unlink(tmpP12).catch(() => {});
    await fsp.unlink(tmpPwd).catch(() => {});
  }

  return { nickname, nssdb, certutilBin };
}

async function uninstallCertForChromeLinux({ nickname, nssdb, certutilBin }) {
  const bin = certutilBin || ['/usr/bin/certutil', '/usr/local/bin/certutil'].find(p => fs.existsSync(p));
  if (!bin) return; // no hay con qué limpiar; ignorar
  await execFileAsync(bin, ['-D', '-d', `sql:${nssdb}`, '-n', nickname])
    .catch(err => console.warn('⚠️ No se pudo limpiar cert NSS:', err.message));
}

// Política equivalente a HKCU\...\AutoSelectCertificateForUrls de Windows.
// Sin esto, Chrome en Linux muestra un diálogo "Select Certificate" en cada
// request mTLS — en modo headless el diálogo nunca aparece y la navegación
// cuelga hasta el timeout. Con esta política, Chrome auto-selecciona el
// cert del NSS db sin intervención.
const LINUX_POLICY_PATHS = [
  '/etc/opt/chrome/policies/managed/dian_extractor.json',
  '/etc/chromium/policies/managed/dian_extractor.json',
];

async function setLinuxChromePolicy(hostname, log = () => {}) {
  // Cubrir múltiples hosts de DIAN porque el flujo cert→documentos cruza dominios:
  //   certificate-vpfe.dian.gov.co (auth con cert)
  //   catalogo-vpfe.dian.gov.co    (puede requerir cert en handoff)
  //   catalogo.dian.gov.co         (alternativa sin -vpfe)
  const patterns = [
    `https://${hostname}`,
    'https://catalogo-vpfe.dian.gov.co',
    'https://catalogo.dian.gov.co',
    'https://certificate-vpfe.dian.gov.co',
  ];
  const uniquePatterns = Array.from(new Set(patterns));
  const policyJson = JSON.stringify({
    AutoSelectCertificateForUrls: uniquePatterns.map(p =>
      JSON.stringify({ pattern: p, filter: {} })
    ),
  }, null, 2);
  let written = 0;
  for (const policyPath of LINUX_POLICY_PATHS) {
    try {
      await fsp.mkdir(path.dirname(policyPath), { recursive: true });
      await fsp.writeFile(policyPath, policyJson, 'utf8');
      written++;
    } catch (err) {
      // Permission denied es común si el proceso no corre como root.
      // No es fatal — al menos lo intentamos en ambas rutas.
    }
  }
  if (written > 0) {
    log(`🔐 Policy Chrome escrita (${written} ruta(s)): auto-select cert para https://${hostname}`);
  } else {
    log(`⚠️ No se pudo escribir policy Chrome (¿proceso sin permisos /etc?). Chrome podría colgar pidiendo cert.`);
  }
  return written > 0;
}

async function clearLinuxChromePolicy() {
  for (const policyPath of LINUX_POLICY_PATHS) {
    await fsp.unlink(policyPath).catch(() => {});
  }
}

function launchChromeForSession(targetUrl, session, forceHeadless = false) {
  console.log(`🚀 [${session.sessionId}] Lanzando Chrome puerto=${session.port} headless=${IS_HEADLESS || forceHeadless}`);
  // En Windows un Chrome real NO usa --no-sandbox (es señal típica de bot y
  // además dispara el cartel "indicador de línea de comandos no admitido").
  // Los flags de sandbox solo hacen falta en Linux/Docker (root).
  const commonChromeFlags = platform === 'win32'
    ? '--no-first-run --no-default-browser-check --disable-sync'
    : '--no-sandbox --disable-setuid-sandbox --no-first-run --no-default-browser-check --disable-sync';
  const headlessFlags = (IS_HEADLESS || forceHeadless)
    ? '--headless=new --no-zygote --disable-gpu --disable-dev-shm-usage --disable-software-rasterizer --disable-crash-reporter --disable-crashpad --disable-breakpad --noerrdialogs'
    : '--window-size=1920,1080';
  const xvfbPrefix = platform === 'linux' && !(IS_HEADLESS || forceHeadless) && !process.env.DISPLAY && fs.existsSync('/usr/bin/xvfb-run')
    ? 'xvfb-run -a --server-args="-screen 0 1920x1080x24" '
    : '';
  const command = `${xvfbPrefix}${CHROME_PATH} ${commonChromeFlags} ${headlessFlags} --remote-debugging-port=${session.port} --user-data-dir="${session.userDataDir}" --new-window "${targetUrl}"`;
  const chromeProcess = exec(command);

  chromeProcess.stderr.on('data', (data) => {
    const msg = data.toString().trim();
    if (!msg) return;
    // Suprimir ruido benigno de Chrome en contenedores Linux (dbus, GPU, GCM, etc.)
    const CHROME_NOISE = [
      /\bdbus\b/i,
      /\bGCM\b/,
      /google_apis\/gcm/i,
      /XDG_RUNTIME_DIR/i,
      /\bEGL\b/i,
      /libGL|MESA|DRM|drm\b/i,
      /\bGPU\b.*error|error.*\bGPU\b/i,
      /Failed to connect to.*dbus/i,
      /\[ERROR:gpu/i,
      /\[ERROR:gl_/i,
      /\[WARNING:gpu/i,
      /TensorFlow|XNNPACK/i,
      /Fontconfig warning/i,
      /NSS|nss_/i,
      /\[ERROR:sandbox/i,
      /\[WARNING:sandbox/i,
    ];
    if (CHROME_NOISE.some(re => re.test(msg))) return;
    console.error(`⚠️ [${session.sessionId}] chrome:`, msg);
  });
  chromeProcess.on('error', (err) => {
    console.error(`❌ [${session.sessionId}] Error al lanzar Chrome:`, err.message);
  });
  chromeProcess.on('close', (code, signal) => {
    if (code !== 0 && signal !== 'SIGTERM') {
      console.error(`❌ [${session.sessionId}] Chrome terminó antes de tiempo (code=${code}, signal=${signal || 'none'})`);
    }
  });

  return chromeProcess;
}

async function waitForDebugger(port, sessionId, maxRetries = 30) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      const res = await axios.get(`http://127.0.0.1:${port}/json/version`);
      console.log(`✅ [${sessionId}] Conectado a Chrome:`, res.data.webSocketDebuggerUrl);
      return true;
    } catch (_) {
      await new Promise(r => setTimeout(r, 1000));
    }
  }
  return false;
}

// ============================================================
// Cola con límite de concurrencia (FIFO)
// ============================================================
let activeSessions = 0;
const waitingQueue = [];

function acquireSlot() {
  return new Promise((resolve) => {
    if (activeSessions < MAX_CONCURRENT) {
      activeSessions++;
      return resolve();
    }
    waitingQueue.push(resolve);
  });
}

function releaseSlot() {
  const next = waitingQueue.shift();
  if (next) {
    next(); // mantiene activeSessions porque el que entra ocupa el slot del que sale
  } else {
    activeSessions--;
  }
}

// ============================================================
// Ejecutor de scraper — modo aislado (scrape)
// ============================================================

async function runScrapeSession({ script, url, tipo, fechaDesde, fechaHasta, userCode, excluirEstados, mode, onStdoutLine, onStderrLine }) {
  await acquireSlot();
  // Modo token (Modo 1): perfil DEDICADO, limpio y persistente (TOKEN_PROFILE_DIR),
  // separado del de certificado. Limpio = sin cert ni extensiones (Cloudflare lo ve
  // como Chrome normal). Persistente = conserva la cookie de clearance de Cloudflare
  // entre corridas, igual que en el VPS Docker (por eso allá funciona "a la 2ª").
  const session = await createSession(buildSessionLabel(tipo, fechaDesde, fechaHasta, userCode), { baseProfileDir: TOKEN_PROFILE_DIR });
  let chromeProcess = null;

  try {
    chromeProcess = launchChromeForSession(url, session);

    const ok = await waitForDebugger(session.port, session.sessionId);
    if (!ok) {
      throw new Error(`No se pudo conectar a Chrome en puerto ${session.port}`);
    }

    const exitCode = await spawnScraper({ script, url, tipo, fechaDesde, fechaHasta, session, onStdoutLine, onStderrLine, excluirEstados, mode });

    return { session, exitCode };
  } finally {
    await clearInProgress(session.downloadPath);
    await cleanupSession(session, chromeProcess, { baseProfileDir: TOKEN_PROFILE_DIR });
    releaseSlot();
  }
}

// ============================================================
// Chrome persistente para el modo sin certificado
// Las cookies de sesión de DIAN no se escriben a disco (son session cookies),
// por eso se mantiene el mismo proceso Chrome vivo entre peticiones.
// ============================================================
let noCertChrome = null; // { process, port } | null

async function ensureNoCertChrome(startUrl) {
  if (noCertChrome) {
    try {
      await axios.get(`http://127.0.0.1:${noCertChrome.port}/json/version`);
      return noCertChrome.port; // Chrome ya está corriendo y responde
    } catch {
      try { noCertChrome.process.kill(); } catch (_) {}
      noCertChrome = null;
    }
  }
  const port = await getFreePort();
  const fakeSession = { sessionId: 'no-cert-persistent', userDataDir: BASE_PROFILE_DIR, port };
  const proc = launchChromeForSession(startUrl, fakeSession);
  const ok = await waitForDebugger(port, 'no-cert-persistent');
  if (!ok) {
    try { proc.kill(); } catch (_) {}
    throw new Error('No se pudo iniciar Chrome en modo sin certificado');
  }
  noCertChrome = { process: proc, port };
  console.log(`🟢 Chrome persistente (sin cert) listo en puerto ${port}`);
  return port;
}

// ============================================================
// Ejecutor de certificado — perfil fijo persistente (comportamiento original)
// Solo 1 sesión a la vez porque comparten C:\chrome-profile.
// ============================================================
let certSessionActive = false;
const certWaitingQueue = [];

function acquireCertSlot() {
  return new Promise((resolve) => {
    if (!certSessionActive) { certSessionActive = true; return resolve(); }
    certWaitingQueue.push(resolve);
  });
}
function releaseCertSlot() {
  const next = certWaitingQueue.shift();
  if (next) { next(); } else { certSessionActive = false; }
}

// Detecta forms HTML que se auto-envían por JavaScript (patrón típico en SAML/SSO
// post-cert-auth de DIAN: /User/CertificateLogin devuelve un <form> apuntando a
// catalogo-vpfe que un browser real ejecutaría con JS).
function parseFormAutoSubmit(html, baseUrl) {
  const hasAutoSubmit =
    /\.submit\s*\(\s*\)/i.test(html) ||
    /onload\s*=\s*["'][^"']*submit/i.test(html) ||
    /document\s*\.\s*forms\b[^.]*\.\s*submit/i.test(html) ||
    /document\s*\.\s*getElementById\([^)]+\)\.submit/i.test(html);
  // Si hay un form con action POST y la página parece HTML auto-redirigir, intentar de todas
  // formas (algunos templates usan IIFE o eventos que nuestros regex no capturan).
  const formMatch = html.match(/<form([^>]*)>([\s\S]*?)<\/form>/i);
  if (!formMatch) return null;
  const attrs = formMatch[1];
  const actionMatch = attrs.match(/\baction\s*=\s*["']([^"']+)["']/i);
  if (!actionMatch) return null;
  // Si no detectamos auto-submit pero la página NO tiene botones visibles ni texto
  // significativo, asumir que es un form de redirect (último recurso).
  const hasVisibleButton = /<button|<input[^>]+type\s*=\s*["']submit["']/i.test(html);
  const looksLikeRedirectForm = !hasAutoSubmit && !hasVisibleButton && html.length < 5000;
  if (!hasAutoSubmit && !looksLikeRedirectForm) return null;

  const formBody = formMatch[2];
  const methodMatch = attrs.match(/\bmethod\s*=\s*["']([^"']+)["']/i);
  const method = (methodMatch ? methodMatch[1] : 'POST').toUpperCase();
  const actionRaw = actionMatch[1];
  const action = actionRaw.startsWith('http')
    ? actionRaw
    : new URL(actionRaw, baseUrl).toString();

  const fields = {};
  const inputRegex = /<input\b[^>]*>/gi;
  let m;
  while ((m = inputRegex.exec(formBody)) !== null) {
    const tag = m[0];
    const nameM = tag.match(/\bname\s*=\s*["']([^"']+)["']/i);
    const valueM = tag.match(/\bvalue\s*=\s*["']([^"']*)["']/i);
    if (nameM) fields[nameM[1]] = valueM ? valueM[1] : '';
  }

  return { action, method, fields, detection: hasAutoSubmit ? 'js-submit' : 'redirect-form' };
}

// Detecta redirects vía <meta http-equiv="refresh"> o JavaScript window.location
function parseClientSideRedirect(html, baseUrl) {
  // Meta refresh: <meta http-equiv="refresh" content="0; url=https://...">
  const metaMatch = html.match(/<meta[^>]+http-equiv\s*=\s*["']refresh["'][^>]+content\s*=\s*["']([^"']+)["']/i);
  if (metaMatch) {
    const urlMatch = metaMatch[1].match(/url\s*=\s*(.+)$/i);
    if (urlMatch) {
      const target = urlMatch[1].trim().replace(/^["']|["']$/g, '');
      return { type: 'meta-refresh', url: target.startsWith('http') ? target : new URL(target, baseUrl).toString() };
    }
  }
  // window.location / location.href / location.replace
  const jsMatch = html.match(/(?:window\.)?location(?:\.href|\.replace\s*\()?\s*=?\s*\(?\s*["']([^"']+)["']/i);
  if (jsMatch) {
    const target = jsMatch[1];
    if (/^https?:\/\/|^\//.test(target)) {
      return { type: 'js-location', url: target.startsWith('http') ? target : new URL(target, baseUrl).toString() };
    }
  }
  return null;
}

async function authenticateWithCertificate(p12Base64, p12Password, targetUrl, log = () => {}) {
  const pfxBuffer = Buffer.from(p12Base64, 'base64');
  // Mapeamos cookies por dominio porque el flujo cert-auth de DIAN cruza hosts:
  // certificate-vpfe.dian.gov.co emite sesión; el form auto-submit lleva a
  // catalogo-vpfe.dian.gov.co donde se emite la sesión real para /Document/.
  const cookiesByDomain = new Map(); // hostname → Map(name → value)
  let currentUrl = targetUrl;
  let lastBody = '';
  let method = 'GET';
  let postBody = null;

  function addCookies(setCookieHeaders, hostname) {
    if (!cookiesByDomain.has(hostname)) cookiesByDomain.set(hostname, new Map());
    const map = cookiesByDomain.get(hostname);
    for (const header of setCookieHeaders) {
      const part = header.split(';')[0];
      const eq = part.indexOf('=');
      if (eq > 0) map.set(part.slice(0, eq).trim(), part.slice(eq + 1).trim());
    }
  }
  function cookieHeaderFor(hostname) {
    const map = cookiesByDomain.get(hostname);
    if (!map || map.size === 0) return '';
    return Array.from(map.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
  }
  function totalCookieCount() {
    let n = 0; for (const m of cookiesByDomain.values()) n += m.size; return n;
  }

  for (let hop = 0; hop < 15; hop++) {
    const parsedUrl = new URL(currentUrl);
    const agent = new https.Agent({ pfx: pfxBuffer, passphrase: p12Password, rejectUnauthorized: true });
    const headers = {};
    const cookieHeader = cookieHeaderFor(parsedUrl.hostname);
    if (cookieHeader) headers.Cookie = cookieHeader;
    if (method === 'POST' && postBody) {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      headers['Content-Length'] = Buffer.byteLength(postBody);
    }

    log(`🔎 [auth hop ${hop}] ${method} ${currentUrl}`);

    const res = await new Promise((resolve, reject) => {
      const req = https.request({
        hostname: parsedUrl.hostname,
        port: parseInt(parsedUrl.port, 10) || 443,
        path: parsedUrl.pathname + parsedUrl.search,
        method,
        agent,
        headers,
      }, resolve);
      req.on('error', reject);
      if (method === 'POST' && postBody) req.write(postBody);
      req.end();
    });

    const setCookieHeaders = res.headers['set-cookie'] || [];
    addCookies(setCookieHeaders, parsedUrl.hostname);
    const contentType = (res.headers['content-type'] || '').toLowerCase();

    if (contentType.includes('html')) {
      lastBody = await new Promise((resolve) => {
        let buf = '';
        res.setEncoding('utf8');
        res.on('data', chunk => { if (buf.length < 50000) buf += chunk; });
        res.on('end', () => resolve(buf));
        res.on('error', () => resolve(buf));
      });
    } else {
      res.resume();
      lastBody = '';
      await new Promise(r => res.on('end', r));
    }

    log(`   ↳ status=${res.statusCode} host=${parsedUrl.hostname} set-cookie=${setCookieHeaders.length} cookies-acumuladas=${totalCookieCount()}`);
    if (res.headers.location) log(`   ↳ location=${res.headers.location}`);

    // 1) Redirect HTTP estándar
    if ([301, 302, 303, 307, 308].includes(res.statusCode) && res.headers.location) {
      const loc = res.headers.location;
      currentUrl = loc.startsWith('http') ? loc : new URL(loc, currentUrl).toString();
      method = 'GET';
      postBody = null;
      continue;
    }

    // 2) Form-POST auto-submit (SAML/SSO de DIAN: cert-vpfe → catalogo-vpfe)
    if (res.statusCode === 200 && lastBody) {
      const formInfo = parseFormAutoSubmit(lastBody, currentUrl);
      if (formInfo) {
        log(`📨 [auth] Form-POST (${formInfo.detection}) → ${formInfo.method} ${formInfo.action} (${Object.keys(formInfo.fields).length} campos)`);
        currentUrl = formInfo.action;
        method = formInfo.method;
        postBody = new URLSearchParams(formInfo.fields).toString();
        continue;
      }
      // 3) Meta-refresh o window.location
      const clientRedirect = parseClientSideRedirect(lastBody, currentUrl);
      if (clientRedirect) {
        log(`🔄 [auth] Redirect cliente (${clientRedirect.type}) → ${clientRedirect.url}`);
        currentUrl = clientRedirect.url;
        method = 'GET';
        postBody = null;
        continue;
      }
      // 4) Diagnóstico: la respuesta es 200 HTML pero no detectamos redirect.
      //    Dumpeamos un snippet para ver qué devolvió DIAN y poder ajustar el parser.
      const snippet = lastBody.replace(/\s+/g, ' ').slice(0, 800);
      log(`⚠️ [auth] Respuesta 200 sin redirect detectado. HTML inicial (800 chars): ${snippet}`);
    }

    break;
  }

  // Aplanar a array para inyección en Puppeteer (Chrome aísla cookies por dominio,
  // así que cada cookie viaja con su hostname original).
  const allCookies = [];
  for (const [domain, map] of cookiesByDomain) {
    for (const [name, value] of map) allCookies.push({ name, value, domain });
  }

  log(`✅ [auth] Final URL: ${currentUrl} | host final: ${new URL(currentUrl).hostname} | total cookies: ${allCookies.length} en ${cookiesByDomain.size} dominio(s)`);
  if (allCookies.length > 0) {
    log(`   ↳ cookies: ${allCookies.map(c => `${c.name}@${c.domain}`).join(', ')}`);
  }

  return { cookies: allCookies, finalUrl: currentUrl };
}

async function runCertificateSession({ script, url, tipo, fechaDesde, fechaHasta, p12Base64, p12Password, userCode, excluirEstados, onStdoutLine, onStderrLine }) {
  await acquireCertSlot();

  const hasCert = !!(p12Base64 && p12Base64.length > 50 && p12Password);

  // ── SIN CERTIFICADO: Chrome persistente que conserva la sesión DIAN en memoria ──
  // Las cookies de DIAN son session cookies (sin expiración) y no sobreviven
  // al cierre de Chrome. Se mantiene el mismo proceso entre peticiones para
  // que la sesión iniciada manualmente la primera vez siga activa.
  if (!hasCert) {
    const sessionId = crypto.randomUUID();
    const folderName = buildSessionLabel(tipo, fechaDesde, fechaHasta, userCode);
    const downloadPath = path.resolve(__dirname, 'downloads', folderName);
    await fsp.mkdir(downloadPath, { recursive: true });
    await markInProgress(downloadPath);
    const session = { sessionId, downloadPath, port: 0, userDataDir: BASE_PROFILE_DIR };
    try {
      session.port = await ensureNoCertChrome(url);
      const exitCode = await spawnScraper({
        script, url, tipo, fechaDesde, fechaHasta,
        session, onStdoutLine, onStderrLine, userCode, excluirEstados,
      });
      return { session, exitCode };
    } finally {
      await clearInProgress(downloadPath);
      // Chrome se cerró via browser.close() en index-certificate.js; limpiar referencia.
      try { if (noCertChrome) noCertChrome.process.kill(); } catch (_) {}
      noCertChrome = null;
      releaseCertSlot();
    }
  }

  // ── CON CERTIFICADO: sesión aislada con perfil efímero ──
  const session = await createSession(buildSessionLabel(tipo, fechaDesde, fechaHasta, userCode));
  let chromeProcess = null;
  let certStoreInfo = null;

  const log = (line) => { console.log(`[${session.sessionId}] ${line}`); if (onStdoutLine) onStdoutLine(line); };

  try {
    const pfxBuffer = Buffer.from(p12Base64, 'base64');

    if (platform === 'win32') {
      // Windows: cert en store del SO + política de registro AutoSelectCertificateForUrls
      // → Chrome auto-selecciona el cert y hace mTLS sin diálogos.
      certStoreInfo = await installCertForChrome(pfxBuffer, p12Password, new URL(url).hostname, log);
    } else {
      // Linux/Docker: equivalente al flujo de Windows pero con NSS + policy file.
      //   1) Cert en NSS db (~/.pki/nssdb) — Chrome busca cliente certs ahí.
      //   2) Policy file en /etc/opt/chrome/policies/managed/ con
      //      AutoSelectCertificateForUrls — sin esto Chrome cuelga en headless
      //      mostrando un diálogo "Select Certificate" invisible.
      // Las dos cosas combinadas dejan que Chrome haga TODO el flujo de auth
      // (incluido el form-POST JS que /User/CertificateLogin ejecuta para
      // saltar a catalogo-vpfe). Nada de cookies del lado de Node — el JS
      // de DIAN tiene lógica que no podemos replicar fuera del browser.
      certStoreInfo = await installCertForChromeLinux(pfxBuffer, p12Password, log);
      await setLinuxChromePolicy(new URL(url).hostname, log);
    }

    chromeProcess = launchChromeForSession(url, session);
    const ok = await waitForDebugger(session.port, session.sessionId);
    if (!ok) throw new Error(`No se pudo conectar a Chrome en puerto ${session.port}`);

    const exitCode = await spawnScraper({ script, url, tipo, fechaDesde, fechaHasta, session, onStdoutLine, onStderrLine, userCode, excluirEstados });
    return { session, exitCode };
  } finally {
    await clearInProgress(session.downloadPath);
    await cleanupSession(session, chromeProcess);
    if (certStoreInfo) {
      if (platform === 'win32') {
        await uninstallCertForChrome(certStoreInfo);
      } else {
        await uninstallCertForChromeLinux(certStoreInfo);
        await clearLinuxChromePolicy();
      }
    }
    releaseCertSlot();
  }
}

// Lógica común de spawn compartida por ambos ejecutores.
// Registra el child process en `activeChildren` para poder matarlo desde un signal handler.
const activeChildren = new Set();

function spawnScraper({ script, url, tipo, fechaDesde, fechaHasta, session, onStdoutLine, onStderrLine, cookiesFile, userCode, keepAlive, excluirEstados, mode }) {
  return new Promise((resolve) => {
    const spawnArgs = [script,
      `--url=${url}`,
      `--tipo=${tipo}`,
      `--fechaDesde=${fechaDesde}`,
      `--fechaHasta=${fechaHasta}`,
      `--debuggingPort=${session.port}`,
      `--downloadPath=${session.downloadPath}`,
    ];
    if (cookiesFile)     spawnArgs.push(`--cookiesFile=${cookiesFile}`);
    if (userCode)        spawnArgs.push(`--userCode=${userCode}`);
    if (keepAlive)       spawnArgs.push('--keepAlive');
    // Si el caller pasa estados a excluir, los pasamos al scraper así filtra antes
    // de descargar (ahorra tiempo y ancho de banda).
    if (excluirEstados)  spawnArgs.push(`--excluirEstados=${excluirEstados}`);
    if (mode)            spawnArgs.push(`--mode=${mode}`);
    const child = spawn('node', spawnArgs, { stdio: ['ignore', 'pipe', 'pipe'] });
    activeChildren.add(child);
    child.on('close', () => activeChildren.delete(child));

    // ── Watchdog anti-cuelgue ──────────────────────────────────────────────
    // Si el scraper se queda esperando algo que nunca llega (reCAPTCHA, página
    // que no responde, cierre de Chrome trabado), el proceso quedaría vivo
    // ocupando un slot de concurrencia para SIEMPRE (crítico en VPS, donde hay
    // que entrar a reiniciar con pm2). Dos límites lo matan solo:
    //   • IDLE  → no emite ningún log por X tiempo = está colgado.
    //   • TOTAL → tope duro de runtime, último recurso.
    // Matar el hijo dispara 'close' → se libera el slot y se limpia Chrome.
    const IDLE_MS  = parseInt(process.env.SCRAPER_IDLE_TIMEOUT_MS, 10) || 4 * 60 * 1000;   // 4 min sin actividad
    const TOTAL_MS = parseInt(process.env.SCRAPER_MAX_RUNTIME_MS, 10)  || 3 * 60 * 60 * 1000;  // 3 h tope duro (con ~1 min/línea las corridas grandes son lentas; el IDLE sigue matando los cuelgues reales)
    let matadoPorWatchdog = false;
    const matarColgado = (motivo) => {
      if (matadoPorWatchdog || child.killed) return;
      matadoPorWatchdog = true;
      const msg = `⏱️ Watchdog: ${motivo} — abortando para liberar el slot`;
      console.error(`[${session.sessionId}]`, msg);
      if (onStderrLine) onStderrLine(msg);
      try { child.kill('SIGKILL'); } catch (_) {}
    };
    let idleTimer = setTimeout(() => matarColgado(`sin actividad por ${Math.round(IDLE_MS / 60000)} min`), IDLE_MS);
    const totalTimer = setTimeout(() => matarColgado(`tope de ${Math.round(TOTAL_MS / 60000)} min excedido`), TOTAL_MS);
    const resetIdle = () => {
      clearTimeout(idleTimer);
      idleTimer = setTimeout(() => matarColgado(`sin actividad por ${Math.round(IDLE_MS / 60000)} min`), IDLE_MS);
    };

    // ¿El scraper emitió su cierre normal? Si NO (watchdog/SIGKILL o crash),
    // lo sintetizamos al final para que el frontend resetee el botón "Procesar".
    let vistoTerminado = false;

    child.stdout.on('data', data => {
      resetIdle();
      const lines = data.toString().split('\n').filter(l => l.trim() !== '');
      for (let line of lines) {
        // Líneas técnicas (DEBUG_ONLY:) → SOLO a la consola del server (logs),
        // NO al log visual de la interfaz/SSE.
        if (line.startsWith('DEBUG_ONLY:')) {
          console.log(`[${session.sessionId}]`, line.slice('DEBUG_ONLY:'.length).trim());
          continue;
        }
        if (line.startsWith('EXCEL_PATH:')) {
          const filename = line.replace('EXCEL_PATH:', '').trim();
          const folderName = path.basename(session.downloadPath);
          line = `EXCEL_PATH:${folderName}/${filename}`;
        }
        console.log(`[${session.sessionId}]`, line);
        if (onStdoutLine) onStdoutLine(line);
        if (line.includes('✅ Proceso terminado')) vistoTerminado = true;
      }
    });
    child.stderr.on('data', data => {
      resetIdle();
      const lines = data.toString().split('\n').filter(l => l.trim() !== '');
      for (const line of lines) {
        console.error(`[${session.sessionId}] ❌`, line);
        if (onStderrLine) onStderrLine(line);
      }
    });
    child.on('close', code => {
      clearTimeout(idleTimer);
      clearTimeout(totalTimer);
      // Si el proceso murió sin emitir su cierre normal (lo mató el watchdog con
      // SIGKILL, o crasheó), sintetizamos la señal para que el frontend resetee
      // el botón "Procesar" en vez de quedar en "procesando" para siempre.
      if (!vistoTerminado && onStdoutLine) onStdoutLine('✅ Proceso terminado');
      resolve(code);
    });
  });
}

// Lee el resultado_*.json escrito por el scraper en la carpeta de la sesión.
function readSessionResult(downloadPath) {
  if (!fs.existsSync(downloadPath)) return null;
  const files = fs.readdirSync(downloadPath)
    .filter(f => f.startsWith('resultado_') && f.endsWith('.json'))
    .map(f => ({
      name: f,
      time: fs.statSync(path.join(downloadPath, f)).mtime.getTime()
    }))
    .sort((a, b) => b.time - a.time);
  if (files.length === 0) return null;
  return JSON.parse(fs.readFileSync(path.join(downloadPath, files[0].name), 'utf8'));
}

// ============================================================
// Vistas
// ============================================================
function parseExtractionFolder(folderName) {
  const parts = folderName.split('_');
  const tipoValid = ['Sent', 'Received'];
  let userCode, tipo, fechaDesde, fechaHasta, date, time;
  // Formatos soportados (el sufijo aleatorio final puede estar o no, por compatibilidad
  // con carpetas creadas antes del cambio):
  //   userCode_tipo_desde_hasta_date_time            (6 partes, legado)
  //   userCode_tipo_desde_hasta_date_time_suffix     (7 partes, nuevo)
  //   tipo_desde_hasta_date_time                     (5 partes, legado, sin userCode)
  //   tipo_desde_hasta_date_time_suffix              (6 partes, nuevo, sin userCode)
  if ((parts.length === 6 || parts.length === 7) && tipoValid.includes(parts[1])) {
    [userCode, tipo, fechaDesde, fechaHasta, date, time] = parts;
  } else if ((parts.length === 5 || parts.length === 6) && tipoValid.includes(parts[0])) {
    [tipo, fechaDesde, fechaHasta, date, time] = parts;
    userCode = null;
  } else {
    return null;
  }
  return { userCode: userCode || null, tipo, fechaDesde, fechaHasta, datetime: `${date} ${time.replace(/-/g, ':')}` };
}

// Rutas de UI (/, /result, /certificate, /history) eliminadas: el worker es
// headless — solo expone la API JSON (/api/cufes, /api/jobs, ...).

app.delete('/history/:folder', (req, res) => {
  const { folder } = req.params;
  if (!/^[A-Za-z0-9_-]+$/.test(folder)) {
    return res.status(400).json({ success: false, message: 'Nombre de carpeta inválido' });
  }
  const downloadsDir = path.resolve(__dirname, 'downloads');
  const fullPath = path.join(downloadsDir, folder);
  if (!fullPath.startsWith(downloadsDir + path.sep)) {
    return res.status(400).json({ success: false, message: 'Ruta no permitida' });
  }
  if (!fs.existsSync(fullPath)) {
    return res.status(404).json({ success: false, message: 'No encontrado' });
  }
  try {
    fs.rmSync(fullPath, { recursive: true, force: true });
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ success: false, message: err.message });
  }
});
// Acepta rutas con subdirectorio: /download/sessionId/archivo.xlsx
app.get('/download/*', (req, res) => {
  const downloadsDir = path.resolve(__dirname, 'downloads');
  const filePath = path.resolve(downloadsDir, req.params[0]);
  if (!filePath.startsWith(downloadsDir + path.sep)) {
    return res.status(400).send('Invalid path');
  }
  if (!fs.existsSync(filePath)) return res.status(404).send('Not found');
  res.download(filePath);
});

// ============================================================
// Utilidades compartidas: buffer de logs acotado, filtro de estados,
// y manager de jobs asíncronos para /api/system/consult
// ============================================================
const LOG_MAX_LINES = parseInt(process.env.LOG_MAX_LINES, 10) || 1000;
function makeLogBuffer() {
  const lines = [];
  return {
    push(line) {
      lines.push(line);
      if (lines.length > LOG_MAX_LINES) lines.splice(0, lines.length - LOG_MAX_LINES);
    },
    all() { return lines.slice(); },
  };
}

function applyExcluirEstados(docs, excluirEstados, logSink) {
  if (!excluirEstados || !Array.isArray(docs) || docs.length === 0) return docs;
  const estados = String(excluirEstados).split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
  if (estados.length === 0) return docs;
  const antes = docs.length;
  const filtered = docs.filter(doc => {
    const estado = (doc['Estado RADIAN'] || doc['EstadoRadian'] || '').toLowerCase().trim();
    return !estados.includes(estado);
  });
  const filtrados = antes - filtered.length;
  if (filtrados > 0 && logSink && typeof logSink.push === 'function') {
    logSink.push(`📊 Filtro post-scraping: ${filtrados} documento(s) excluidos por estado RADIAN`);
  }
  return filtered;
}

// Jobs asíncronos — el cliente recibe un jobId al instante y consulta /api/jobs/:id
// hasta ver status='done' o 'error'. Resuelve los timeouts de Laravel en consultas largas.
const JOB_TTL_MS = (parseInt(process.env.JOB_TTL_HOURS, 10) || 2) * 3_600_000;
const jobs = new Map();

function createJob(meta) {
  const id = crypto.randomUUID();
  const job = {
    id,
    status: 'running',
    log: makeLogBuffer(),
    meta,
    data: null,
    error: null,
    folder: null,
    exitCode: null,
    partial: false,
    createdAt: Date.now(),
    finishedAt: null,
  };
  jobs.set(id, job);
  return job;
}

function finishJob(id, { success, partial, exitCode, data, folder }) {
  const job = jobs.get(id);
  if (!job) return;
  job.status = success ? 'done' : (partial ? 'done' : 'error');
  job.exitCode = exitCode;
  job.partial = !!partial;
  job.data = data || [];
  job.folder = folder || null;
  job.finishedAt = Date.now();
  if (!success && !partial) job.error = 'El scraper finalizó sin generar resultados';
}

function failJob(id, errorMsg) {
  const job = jobs.get(id);
  if (!job) return;
  job.status = 'error';
  job.error = errorMsg;
  job.finishedAt = Date.now();
}

function cleanupJobs() {
  const now = Date.now();
  for (const [id, job] of jobs) {
    const ageRef = job.finishedAt || job.createdAt;
    if (now - ageRef > JOB_TTL_MS) jobs.delete(id);
  }
}
setInterval(cleanupJobs, 600_000).unref();

app.get('/api/jobs/:id', (req, res) => {
  const job = jobs.get(req.params.id);
  if (!job) return res.status(404).json({ success: false, message: 'Job no encontrado o expirado' });
  res.json({
    success: true,
    jobId: job.id,
    status: job.status,           // 'running' | 'done' | 'error'
    partial: job.partial,         // true si terminó con error pero hay datos parciales
    exitCode: job.exitCode,
    folder: job.folder,
    meta: job.meta,
    error: job.error,
    log: job.log.all(),
    data: job.status === 'done' ? (job.data || []) : null,
    createdAt: job.createdAt,
    finishedAt: job.finishedAt,
  });
});

app.get('/api/jobs', (req, res) => {
  res.json({
    success: true,
    jobs: Array.from(jobs.values()).map(j => ({
      jobId: j.id,
      status: j.status,
      partial: j.partial,
      folder: j.folder,
      meta: j.meta,
      createdAt: j.createdAt,
      finishedAt: j.finishedAt,
      docs: j.data ? j.data.length : 0,
    })),
  });
});

// ============================================================
// Endpoints UI (mantienen el flujo de vista, ya no comparten estado)
// ============================================================

// SSE por sesión: el cliente abre /events?sessionId=xxx ANTES de POST /scrape
const sseClients = new Map(); // sessionId -> res

app.get('/events', (req, res) => {
  const { sessionId } = req.query;
  if (!sessionId) return res.status(400).end('sessionId requerido');
  res.set({
    'Content-Type': 'text/event-stream',
    'Cache-Control': 'no-cache',
    Connection: 'keep-alive',
  });
  res.flushHeaders();
  sseClients.set(sessionId, res);
  req.on('close', () => sseClients.delete(sessionId));
});

app.post('/scrape', (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, sessionId } = req.body;
  if (!url) return res.send('URL es requerida');
  const sseId = sessionId || null;

  runScrapeSession({
    script: 'index.js',
    url, tipo, fechaDesde, fechaHasta,
    onStdoutLine: (line) => {
      const c = sseId && sseClients.get(sseId);
      if (c) c.write(`data: ${line}\n\n`);
    },
    onStderrLine: (line) => {
      const c = sseId && sseClients.get(sseId);
      if (c) c.write(`data: ❌ ${line}\n\n`);
    },
  }).catch(err => {
    console.error('scrape error:', err.message);
  }).finally(() => {
    const c = sseId && sseClients.get(sseId);
    if (c) { c.end(); sseClients.delete(sseId); }
  });

  res.redirect('/result');
});

// Igual que /scrape pero corre index.js en modo CUFE: NO descarga ZIPs, solo lee
// el CUFE (data-id) de cada fila del listado y genera el resultado JSON/Excel.
app.post('/scrape-cufes', (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, excluirEstados, sessionId } = req.body;
  if (!url) return res.status(400).json({ message: 'URL es requerida' });
  const sseId = sessionId || null;
  const emit = (line) => { const c = sseId && sseClients.get(sseId); if (c) c.write(`data: ${line}\n\n`); };

  runScrapeSession({
    script: 'index.js',
    mode: 'cufes',
    url, tipo, fechaDesde, fechaHasta, excluirEstados,
    onStdoutLine: emit,
    onStderrLine: (line) => emit(`❌ ${line}`),
  }).then(({ session }) => {
    // Emitir la lista de CUFEs al navegador para el PASO 2 (generar reporte con ellos).
    let registros = readSessionResult(session.downloadPath);
    if (!Array.isArray(registros)) registros = [];
    registros = applyExcluirEstados(registros, excluirEstados, { push: emit });
    const cufes = registros.map(r => r && r.cufe).filter(Boolean);
    emit(`CUFES_JSON:${JSON.stringify(cufes)}`);
  }).catch(err => {
    console.error('scrape-cufes error:', err.message);
    emit(`❌ ${err.message}`);
    emit('CUFES_JSON:[]'); // marca de fin del paso 1 aunque haya fallado
  }).finally(() => {
    const c = sseId && sseClients.get(sseId);
    if (c) { c.end(); sseClients.delete(sseId); }
  });

  res.status(200).json({ message: 'CUFEs en proceso' });
});

// PASO 2 de la vista de CUFEs: recibe los CUFEs YA obtenidos + certificado y arma
// el reporte pidiendo los XML al API externo. Transmite el avance por SSE (mismo
// canal que el paso 1) y emite EXCEL_PATH al terminar para ofrecer la descarga.
app.post('/generate-cufes-report', (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, p12Base64, p12Password, sessionId } = req.body;
  const sseId = sessionId || null;
  const emit = (line) => { const c = sseId && sseClients.get(sseId); if (c) c.write(`data: ${line}\n\n`); };
  const closeSse = () => { const c = sseId && sseClients.get(sseId); if (c) { c.end(); sseClients.delete(sseId); } };

  // cufes puede venir como JSON string (desde el navegador) o como array.
  let cufes = req.body.cufes;
  try { if (typeof cufes === 'string') cufes = JSON.parse(cufes); } catch (_) { cufes = null; }
  if (!Array.isArray(cufes)) cufes = [];
  cufes = cufes.map(c => String(c || '').trim()).filter(Boolean);

  res.status(200).json({ message: 'Generación de reporte iniciada' });

  (async () => {
    try {
      if (!process.env.XML_QUERY_API_URL) throw new Error('XML_QUERY_API_URL no está configurada en el servidor');
      if (cufes.length === 0) throw new Error('No hay CUFEs para generar el reporte (obtené los CUFEs primero)');
      if (!p12Base64 || !p12Password) throw new Error('Falta el certificado .p12 o la contraseña');

      emit(`🧾 Generando reporte de ${cufes.length} CUFE(s)...`);
      const { downloadPath, folder } = await createReportFolder({ tipo, fechaDesde, fechaHasta });
      const { documents, fail, excelBasename } = await reportFromCufes({
        cufes, p12Base64, p12Password, url, downloadPath, onLog: emit,
      });

      if (documents.length === 0) {
        emit(`⚠️ El API no devolvió XML válidos (${fail} sin XML). No se generó reporte.`);
      } else {
        emit(`✅ Reporte generado: ${documents.length} documento(s)` + (fail ? ` (${fail} sin XML)` : ''));
        if (excelBasename) emit(`EXCEL_PATH:${folder}/${excelBasename}`);
      }
    } catch (err) {
      emit(`❌ ${err.message}`);
    } finally {
      emit('✅ Proceso terminado');
      closeSse();
    }
  })();
});


// ============================================================
// API JSON
// ============================================================

app.post('/api/scrape', async (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, excluirEstados, async: asyncMode } = req.body;

  if (asyncMode === true || asyncMode === '1' || asyncMode === 1) {
    const job = createJob({ metodo: 'url', tipo, fechaDesde, fechaHasta });
    runScrapeSession({
      script: 'index.js',
      url, tipo, fechaDesde, fechaHasta, excluirEstados,
      onStdoutLine: (line) => job.log.push(line),
      onStderrLine: (line) => job.log.push(`❌ ${line}`),
    }).then(({ session, exitCode }) => {
      const rawData = readSessionResult(session.downloadPath) || [];
      finishJob(job.id, {
        success: exitCode === 0 && rawData.length > 0,
        partial: exitCode !== 0 && rawData.length > 0,
        exitCode,
        data: Array.isArray(rawData) ? applyExcluirEstados(rawData, excluirEstados, job.log) : [],
        folder: path.basename(session.downloadPath),
      });
    }).catch(err => failJob(job.id, err.message));
    return res.status(202).json({
      success: true,
      jobId: job.id,
      statusUrl: `/api/jobs/${job.id}`,
      message: 'Extracción iniciada — consulte statusUrl para el resultado',
    });
  }

  const logBuf = makeLogBuffer();
  try {
    const { session, exitCode } = await runScrapeSession({
      script: 'index.js',
      url, tipo, fechaDesde, fechaHasta, excluirEstados,
      onStdoutLine: (line) => logBuf.push(line),
      onStderrLine: (line) => logBuf.push(`❌ ${line}`),
    });
    const data = readSessionResult(session.downloadPath);
    if (exitCode !== 0 && !data) {
      return res.status(500).json({ error: 'Error al ejecutar el proceso hijo', log: logBuf.all() });
    }
    if (!data) {
      return res.status(404).json({ error: 'No se encontró el archivo JSON generado', log: logBuf.all() });
    }
    res.json({ partial: exitCode !== 0, log: logBuf.all(), data });
  } catch (err) {
    res.status(500).json({ error: err.message, log: logBuf.all() });
  }
});


// Endpoint para extraer CUFEs de ZIPs ya descargados
const { extractCufes, writeOutputs } = require('./unzip');
// Cliente del API externo de "XML por CUFEs" (método token -> CUFEs -> XML -> reporte)
const { deriveAmbiente, fetchXmlsByCufes } = require('./xml-query-api');

app.post('/api/extract-cufes', async (req, res) => {
  try {
    const { folder } = req.body;
    let targetFolder;

    if (folder) {
      targetFolder = path.resolve(__dirname, 'downloads', folder);
    } else {
      const downloadsDir = path.resolve(__dirname, 'downloads');
      const subfolders = fs.readdirSync(downloadsDir)
        .filter(f => fs.statSync(path.join(downloadsDir, f)).isDirectory())
        .map(f => ({ name: f, time: fs.statSync(path.join(downloadsDir, f)).mtime.getTime() }))
        .sort((a, b) => b.time - a.time);

      if (subfolders.length > 0) {
        targetFolder = path.join(downloadsDir, subfolders[0].name);
      } else {
        targetFolder = downloadsDir;
      }
    }

    if (!fs.existsSync(targetFolder)) {
      return res.status(404).json({ success: false, message: `Carpeta no encontrada: ${folder}` });
    }

    const result = await extractCufes(targetFolder);
    res.json(result);
  } catch (error) {
    res.status(500).json({ success: false, message: error.message });
  }
});

// ============================================================
// /api/cufes — obtiene los CUFEs del listado SIN descargar los ZIPs.
// Usa el método token/URL (el CUFE es el data-id de cada fila del listado).
// Soporta modo síncrono y asíncrono (async: true + polling /api/jobs/:id).
// ============================================================
app.post('/api/cufes', async (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, excluirEstados, async: asyncMode } = req.body;

  if (!url || !tipo || !fechaDesde || !fechaHasta) {
    return res.status(400).json({
      success: false,
      message: 'Parámetros incompletos: url, tipo, fechaDesde y fechaHasta son obligatorios',
      data: [],
    });
  }

  // Modo asíncrono: devuelve un jobId al instante; el cliente consulta
  // /api/jobs/:id para el resultado. Evita timeouts en rangos de fechas grandes.
  if (asyncMode === true || asyncMode === '1' || asyncMode === 1) {
    const job = createJob({ metodo: 'cufes', tipo, fechaDesde, fechaHasta });
    runScrapeSession({
      script: 'index.js', mode: 'cufes',
      url, tipo, fechaDesde, fechaHasta, excluirEstados,
      onStdoutLine: (line) => job.log.push(line),
      onStderrLine: (line) => job.log.push(`❌ ${line}`),
    }).then(({ session, exitCode }) => {
      const rawData = readSessionResult(session.downloadPath) || [];
      const data = Array.isArray(rawData) ? applyExcluirEstados(rawData, excluirEstados, job.log) : [];
      finishJob(job.id, {
        success: exitCode === 0 && data.length > 0,
        partial: exitCode !== 0 && data.length > 0,
        exitCode,
        data,
        folder: path.basename(session.downloadPath),
      });
    }).catch(err => failJob(job.id, err.message));

    return res.status(202).json({
      success: true,
      jobId: job.id,
      statusUrl: `/api/jobs/${job.id}`,
      message: 'Extracción de CUFEs iniciada — consulte statusUrl para el resultado',
    });
  }

  // Modo síncrono (el cliente debe tener un timeout amplio para rangos grandes).
  const logBuf = makeLogBuffer();
  try {
    const { session, exitCode } = await runScrapeSession({
      script: 'index.js', mode: 'cufes',
      url, tipo, fechaDesde, fechaHasta, excluirEstados,
      onStdoutLine: (line) => logBuf.push(line),
      onStderrLine: (line) => logBuf.push(`❌ ${line}`),
    });

    let rawData = readSessionResult(session.downloadPath);
    if (!Array.isArray(rawData)) rawData = [];
    rawData = applyExcluirEstados(rawData, excluirEstados, logBuf);

    if (rawData.length === 0) {
      return res.json({
        success: false,
        partial: exitCode !== 0,
        message: exitCode !== 0
          ? 'El proceso no pudo completarse'
          : 'No se encontraron CUFEs en el rango indicado',
        total: 0,
        log: logBuf.all(),
        data: [],
      });
    }

    res.json({
      success: true,
      partial: exitCode !== 0,
      message: exitCode === 0
        ? `Se obtuvieron ${rawData.length} CUFE(s)`
        : `Resultado parcial: ${rawData.length} CUFE(s) antes del corte`,
      total: rawData.length,
      folder: path.basename(session.downloadPath),
      log: logBuf.all(),
      data: rawData,
    });
  } catch (err) {
    res.status(500).json({ success: false, message: err.message, data: [] });
  }
});

// ============================================================
// /api/xml-by-cufes — método token -> CUFEs del listado -> API XML -> reporte.
// El extractor NO descarga ZIPs de la DIAN: saca los CUFEs del listado (rápido,
// mismo método token que /api/cufes) y le pide los XML al API externo
// (XML_QUERY_API_URL), que los consulta con el certificado (.p12). Con esos XML
// arma el MISMO reporte (JSON + Excel) que los demás métodos.
// Soporta modo síncrono y asíncrono (async: true + polling /api/jobs/:id).
// ============================================================

// Borra los resultados intermedios del paso CUFE (resultado_*.json/.xlsx) para
// dejar en la carpeta solo el reporte final. Seguro: el proceso hijo ya terminó.
function cleanIntermediateResults(dir) {
  try {
    for (const f of fs.readdirSync(dir)) {
      if (/^resultado_.*\.(json|xlsx)$/i.test(f)) {
        try { fs.unlinkSync(path.join(dir, f)); } catch (_) {}
      }
    }
  } catch (_) {}
}

// Crea una carpeta de descargas para un reporte generado por CUFEs (sin Chrome).
// Usa la misma convención de nombre que las sesiones para que /history y /download
// la reconozcan. Devuelve { downloadPath, folder }.
async function createReportFolder({ tipo, fechaDesde, fechaHasta, userCode } = {}) {
  const folder = buildSessionLabel(tipo, fechaDesde, fechaHasta, userCode) || crypto.randomUUID();
  const downloadPath = path.resolve(__dirname, 'downloads', folder);
  await fsp.mkdir(downloadPath, { recursive: true });
  return { downloadPath, folder };
}

// Núcleo reutilizable: dada una lista de CUFEs, pide los XML al API externo, los
// parsea y arma el reporte (JSON + Excel) en downloadPath. NO saca los CUFEs (eso
// lo hace quien llama). Devuelve { documents, ok, fail, excelBasename }.
async function reportFromCufes({ cufes, p12Base64, p12Password, ambiente, url, downloadPath, onLog = () => {} }) {
  const ambNum = Number(ambiente);
  const amb = (ambNum === 1 || ambNum === 2) ? ambNum : deriveAmbiente(url);
  onLog(`🔎 ${cufes.length} CUFE(s) — ambiente ${amb} (${amb === 2 ? 'habilitación' : 'producción'})`);

  onLog(`🌐 Consultando XML de ${cufes.length} CUFE(s) al API...`);
  const { documents, ok, fail } = await fetchXmlsByCufes({
    cufes, certificate: p12Base64, password: p12Password, ambiente: amb, log: onLog,
  });
  onLog(`📥 XML recibidos: ${ok} OK, ${fail} sin XML (de ${cufes.length} CUFE)`);

  let excelBasename = null;
  if (documents.length > 0) {
    cleanIntermediateResults(downloadPath);
    const { outputExcel } = await writeOutputs(documents, downloadPath);
    excelBasename = path.basename(outputExcel);
  }
  return { documents, ok, fail, excelBasename };
}

// Flujo end-to-end (sync + async): saca los CUFEs del listado y arma el reporte
// pidiendo los XML al API externo. Devuelve los documentos parseados + contadores.
async function runXmlByCufesFlow({ url, tipo, fechaDesde, fechaHasta, excluirEstados, p12Base64, p12Password, ambiente, onLog = () => {} }) {
  // 1) Sacar los CUFEs del listado con el método token que ya existe (index.js mode=cufes).
  const { session, exitCode } = await runScrapeSession({
    script: 'index.js', mode: 'cufes',
    url, tipo, fechaDesde, fechaHasta, excluirEstados,
    onStdoutLine: onLog,
    onStderrLine: (line) => onLog(`❌ ${line}`),
  });

  // 2) Leer los CUFEs recolectados y aplicar el filtro de estados (defensa extra).
  let registros = readSessionResult(session.downloadPath);
  if (!Array.isArray(registros)) registros = [];
  registros = applyExcluirEstados(registros, excluirEstados, { push: onLog });
  const cufes = registros.map(r => r && r.cufe).filter(Boolean);

  if (cufes.length === 0) {
    onLog('🔎 0 CUFE(s) recolectados en el rango indicado');
    return { session, exitCode, documents: [], cufesTotal: 0, ok: 0, fail: 0, partial: exitCode !== 0 };
  }

  // 3) CUFEs -> API XML -> reporte (núcleo compartido con /generate-cufes-report).
  const { documents, ok, fail } = await reportFromCufes({
    cufes, p12Base64, p12Password, ambiente, url, downloadPath: session.downloadPath, onLog,
  });

  return { session, exitCode, documents, cufesTotal: cufes.length, ok, fail, partial: exitCode !== 0 };
}

app.post('/api/xml-by-cufes', async (req, res) => {
  const { url, tipo, fechaDesde, fechaHasta, excluirEstados, p12Base64, p12Password, ambiente, async: asyncMode } = req.body;

  const faltan = [];
  if (!url) faltan.push('url');
  if (!tipo) faltan.push('tipo');
  if (!fechaDesde) faltan.push('fechaDesde');
  if (!fechaHasta) faltan.push('fechaHasta');
  if (!p12Base64) faltan.push('p12Base64');
  if (!p12Password) faltan.push('p12Password');
  if (faltan.length > 0) {
    return res.status(400).json({
      success: false,
      message: `Parámetros incompletos: ${faltan.join(', ')} ${faltan.length === 1 ? 'es obligatorio' : 'son obligatorios'}`,
      data: [],
    });
  }
  if (!process.env.XML_QUERY_API_URL) {
    return res.status(500).json({
      success: false,
      message: 'XML_QUERY_API_URL no está configurada en el servidor (URL del API de XML por CUFEs)',
      data: [],
    });
  }

  // Modo asíncrono: jobId al instante + polling /api/jobs/:id. Recomendado para rangos grandes.
  if (asyncMode === true || asyncMode === '1' || asyncMode === 1) {
    const job = createJob({ metodo: 'xml-by-cufes', tipo, fechaDesde, fechaHasta });
    runXmlByCufesFlow({
      url, tipo, fechaDesde, fechaHasta, excluirEstados, p12Base64, p12Password, ambiente,
      onLog: (line) => job.log.push(line),
    }).then(({ session, documents, partial }) => {
      finishJob(job.id, {
        success: documents.length > 0,
        partial: partial && documents.length > 0,
        exitCode: partial ? 1 : 0,
        data: documents,
        folder: path.basename(session.downloadPath),
      });
    }).catch(err => failJob(job.id, err.message));

    return res.status(202).json({
      success: true,
      jobId: job.id,
      statusUrl: `/api/jobs/${job.id}`,
      message: 'Extracción iniciada — consulte statusUrl para el resultado',
    });
  }

  // Modo síncrono (el cliente debe tener un timeout amplio para rangos grandes).
  const logBuf = makeLogBuffer();
  try {
    const { session, documents, cufesTotal, ok, fail, partial } = await runXmlByCufesFlow({
      url, tipo, fechaDesde, fechaHasta, excluirEstados, p12Base64, p12Password, ambiente,
      onLog: (line) => logBuf.push(line),
    });

    if (cufesTotal === 0) {
      return res.json({
        success: false,
        partial,
        message: partial ? 'El proceso no pudo completarse' : 'No se encontraron CUFEs en el rango indicado',
        total: 0,
        log: logBuf.all(),
        data: [],
      });
    }

    if (documents.length === 0) {
      return res.json({
        success: false,
        partial,
        message: `Se encontraron ${cufesTotal} CUFE(s) pero el API no devolvió XML válidos`,
        total: 0,
        cufesTotal,
        ok, fail,
        log: logBuf.all(),
        data: [],
      });
    }

    res.json({
      success: true,
      partial,
      message: `Reporte generado: ${documents.length} documento(s) de ${cufesTotal} CUFE(s)` + (fail ? ` (${fail} sin XML)` : ''),
      total: documents.length,
      cufesTotal,
      ok, fail,
      folder: path.basename(session.downloadPath),
      log: logBuf.all(),
      data: documents,
    });
  } catch (err) {
    res.status(500).json({ success: false, message: err.message, log: logBuf.all(), data: [] });
  }
});

// ============================================================
// /api/xml-by-cufes-list — igual que /api/xml-by-cufes pero recibe la LISTA de
// CUFEs ya armada (no la saca del listado). Útil cuando el cliente ya tiene los
// CUFEs (p. ej. de /api/cufes) y solo quiere el reporte. Sync o async.
// El ambiente sale de `ambiente` (1/2) o se deriva de `url`.
// ============================================================
app.post('/api/xml-by-cufes-list', async (req, res) => {
  const { url, ambiente, tipo, fechaDesde, fechaHasta, p12Base64, p12Password, async: asyncMode } = req.body;

  let cufes = Array.isArray(req.body.cufes) ? req.body.cufes : [];
  cufes = cufes.map(c => String(c || '').trim()).filter(Boolean);

  const faltan = [];
  if (cufes.length === 0) faltan.push('cufes');
  if (!p12Base64) faltan.push('p12Base64');
  if (!p12Password) faltan.push('p12Password');
  if (!url && !(Number(ambiente) === 1 || Number(ambiente) === 2)) faltan.push('url o ambiente');
  if (faltan.length > 0) {
    return res.status(400).json({ success: false, message: `Parámetros incompletos: ${faltan.join(', ')}`, data: [] });
  }
  if (!process.env.XML_QUERY_API_URL) {
    return res.status(500).json({ success: false, message: 'XML_QUERY_API_URL no está configurada en el servidor', data: [] });
  }

  const run = async (onLog) => {
    const { downloadPath, folder } = await createReportFolder({ tipo, fechaDesde, fechaHasta });
    const { documents, ok, fail } = await reportFromCufes({ cufes, p12Base64, p12Password, ambiente, url, downloadPath, onLog });
    return { folder, documents, ok, fail };
  };

  if (asyncMode === true || asyncMode === '1' || asyncMode === 1) {
    const job = createJob({ metodo: 'xml-by-cufes-list', tipo, fechaDesde, fechaHasta });
    run((line) => job.log.push(line))
      .then(({ folder, documents }) => {
        finishJob(job.id, { success: documents.length > 0, partial: false, exitCode: documents.length > 0 ? 0 : 1, data: documents, folder });
      })
      .catch(err => failJob(job.id, err.message));
    return res.status(202).json({ success: true, jobId: job.id, statusUrl: `/api/jobs/${job.id}`, message: 'Generación iniciada — consulte statusUrl para el resultado' });
  }

  const logBuf = makeLogBuffer();
  try {
    const { folder, documents, ok, fail } = await run((line) => logBuf.push(line));
    if (documents.length === 0) {
      return res.json({ success: false, message: `El API no devolvió XML válidos (${fail} sin XML de ${cufes.length} CUFE)`, total: 0, ok, fail, log: logBuf.all(), data: [] });
    }
    res.json({
      success: true,
      message: `Reporte generado: ${documents.length} documento(s) de ${cufes.length} CUFE(s)` + (fail ? ` (${fail} sin XML)` : ''),
      total: documents.length,
      ok, fail,
      folder,
      log: logBuf.all(),
      data: documents,
    });
  } catch (err) {
    res.status(500).json({ success: false, message: err.message, log: logBuf.all(), data: [] });
  }
});

// ============================================================
// /api/system/consult — endpoint unificado (URL o certificado)
// Soporta modo síncrono y asíncrono (async: true + polling /api/jobs/:id).
// Compatible con cualquier cliente HTTP: Laravel, Python, curl, etc.
// ============================================================
app.post('/api/system/consult', async (req, res) => {
  const { metodo, url, tipo, fechaDesde, fechaHasta, excluirEstados, p12Base64, p12Password, userCode, async: asyncMode } = req.body;

  if (!url || !tipo || !fechaDesde || !fechaHasta) {
    return res.status(400).json({ success: false, message: 'Parámetros incompletos', data: [] });
  }

  const script = 'index.js';
  const runner = runScrapeSession;
  // metodo 'cufes' usa el flujo token (index.js) pero en modo CUFE (no descarga).
  const mode = metodo === 'cufes' ? 'cufes' : undefined;

  // Modo asíncrono: el cliente recibe un jobId al instante y consulta /api/jobs/:id
  // para polling. Evita timeouts de Laravel/Nginx en extracciones largas.
  if (asyncMode === true || asyncMode === '1' || asyncMode === 1) {
    const job = createJob({ metodo, tipo, fechaDesde, fechaHasta, userCode });
    runner({
      script, mode, url, tipo, fechaDesde, fechaHasta, p12Base64, p12Password, userCode, excluirEstados,
      onStdoutLine: (line) => job.log.push(line),
      onStderrLine: (line) => job.log.push(`❌ ${line}`),
    }).then(({ session, exitCode }) => {
      const rawData = readSessionResult(session.downloadPath) || [];
      finishJob(job.id, {
        success: exitCode === 0 && rawData.length > 0,
        partial: exitCode !== 0 && rawData.length > 0,
        exitCode,
        data: Array.isArray(rawData) ? applyExcluirEstados(rawData, excluirEstados, job.log) : [],
        folder: path.basename(session.downloadPath),
      });
    }).catch(err => {
      failJob(job.id, err.message);
    });
    return res.status(202).json({
      success: true,
      jobId: job.id,
      statusUrl: `/api/jobs/${job.id}`,
      message: 'Extracción iniciada — consulte statusUrl para el resultado',
    });
  }

  // Modo síncrono (legado) — Laravel debe tener timeout amplio
  const logBuf = makeLogBuffer();

  try {
    const { session, exitCode } = await runner({
      script, mode, url, tipo, fechaDesde, fechaHasta, p12Base64, p12Password, userCode, excluirEstados,
      onStdoutLine: (line) => logBuf.push(line),
      onStderrLine: (line) => logBuf.push(`❌ ${line}`),
    });

    let rawData = readSessionResult(session.downloadPath);
    if (!Array.isArray(rawData)) rawData = [];

    // Defensa post-scraping: filtra de nuevo por si el scraper no detectó algún estado.
    rawData = applyExcluirEstados(rawData, excluirEstados, logBuf);

    if (exitCode !== 0 && rawData.length === 0) {
      return res.json({
        success: false,
        message: 'El scraper no pudo completar el proceso',
        log: logBuf.all(),
        data: [],
      });
    }

    if (rawData.length === 0) {
      return res.json({
        success: false,
        message: 'El scraper finalizó pero no generó resultados (0 documentos descargados)',
        log: logBuf.all(),
        data: [],
      });
    }

    res.json({
      success: true,
      partial: exitCode !== 0,
      message: exitCode === 0
        ? `Se encontraron ${rawData.length} documento(s)`
        : `Resultado parcial: ${rawData.length} documento(s) descargados antes del corte`,
      log: logBuf.all(),
      data: rawData,
    });

  } catch (error) {
    res.status(500).json({ success: false, message: error.message, log: logBuf.all(), data: [] });
  }
});

// ============================================================
// Limpieza automática de descargas antiguas
// ============================================================
const CLEANUP_MAX_AGE_MS = (parseInt(process.env.CLEANUP_MAX_AGE_HOURS, 10) || 24) * 3_600_000;
const CLEANUP_INTERVAL_MS = (parseInt(process.env.CLEANUP_INTERVAL_HOURS, 10) || 6) * 3_600_000;

function cleanupOldDownloads() {
  const downloadsDir = path.resolve(__dirname, 'downloads');
  if (!fs.existsSync(downloadsDir)) return;
  const now = Date.now();
  let removed = 0;
  let skipped = 0;
  for (const entry of fs.readdirSync(downloadsDir)) {
    const fullPath = path.join(downloadsDir, entry);
    try {
      const stat = fs.statSync(fullPath);
      if (!stat.isDirectory()) continue;
      if (now - stat.mtime.getTime() <= CLEANUP_MAX_AGE_MS) continue;
      // No borrar carpetas con extracciones en curso (puede pasar si una corrida
      // tarda más que CLEANUP_MAX_AGE_HOURS).
      if (fs.existsSync(path.join(fullPath, INPROGRESS_FILE))) { skipped++; continue; }
      fs.rmSync(fullPath, { recursive: true, force: true });
      removed++;
    } catch (_) { /* carpeta ya eliminada o bloqueada */ }
  }
  if (removed > 0 || skipped > 0) {
    console.log(`[cleanup] ${removed} carpeta(s) eliminadas, ${skipped} preservadas (en curso) — umbral ${CLEANUP_MAX_AGE_MS / 3_600_000}h`);
  }
}

// ============================================================
// Cleanup de política de Chrome huérfana (Windows)
// Si una sesión con cert crashea sin pasar por uninstallCertForChrome,
// la entrada en HKCU\SOFTWARE\Policies\Google\Chrome\AutoSelectCertificateForUrls
// queda apuntando al cert anterior y contamina la siguiente sesión.
// ============================================================
async function cleanupOrphanChromePolicy() {
  if (platform !== 'win32') return;
  const ps = `
$ProgressPreference = 'SilentlyContinue'
$ErrorActionPreference = 'SilentlyContinue'
$regPath = 'HKCU:\\SOFTWARE\\Policies\\Google\\Chrome\\AutoSelectCertificateForUrls'
if (Test-Path $regPath) {
  Remove-ItemProperty -Path $regPath -Name '1' -ErrorAction SilentlyContinue | Out-Null
}
exit 0
`;
  try {
    await runPowerShell(ps);
    console.log('🧹 Política de Chrome huérfana limpiada (si existía)');
  } catch (_) {
    // Best-effort: si falla acá, uninstallCertForChrome igual reescribe la política
    // en la próxima sesión con cert. No es crítico — silenciar.
  }
}

// ============================================================
// Apagado limpio — mata scrapers activos y Chromes huérfanos
// ============================================================
let shuttingDown = false;
function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`\n[shutdown] señal recibida: ${signal} — cerrando ${activeChildren.size} scraper(s) y Chrome persistente`);
  for (const child of activeChildren) {
    try { child.kill(); } catch (_) {}
  }
  try { if (noCertChrome) noCertChrome.process.kill(); } catch (_) {}
  noCertChrome = null;
  // Limpieza best-effort de la política Chrome al apagar
  cleanupOrphanChromePolicy().finally(() => process.exit(0));
  // Falla-seguro: si el cleanup tarda demasiado, salir igual
  setTimeout(() => process.exit(0), 5000).unref();
}
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

app.listen(3001, () => {
  console.log(`🚀 Servidor corriendo en http://localhost:3001 (concurrencia=${MAX_CONCURRENT})`);
  cleanupOldDownloads();
  setInterval(cleanupOldDownloads, CLEANUP_INTERVAL_MS);
  cleanupOrphanChromePolicy();
});
