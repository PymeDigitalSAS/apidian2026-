// xml-query-api.js — cliente del API externo de "XML por CUFE".
//
// NOTA: archivo RECONSTRUIDO (el original se perdió). Mantiene la misma interfaz
// que consumía server.js (deriveAmbiente, fetchXmlsByCufes). Dada una lista de
// CUFEs + certificado .p12, pide los XML al API externo (XML_QUERY_API_URL, que
// los consulta con GetXmlByDocumentKey) y los parsea con parseUblXml de unzip.js
// para armar los documentos del reporte.
const axios = require('axios');
const { parseUblXml } = require('./unzip');
require('dotenv').config();

// URL del API (Laravel) que consulta los XML por CUFE con el certificado, p.ej.
// http://TU-API/api/ubl2.1/xml/by-cufes  — y su API key (header X-Api-Key).
const XML_QUERY_API_URL = process.env.XML_QUERY_API_URL || '';
const XML_QUERY_API_KEY = process.env.XML_QUERY_API_KEY || '';

// Deriva el ambiente (1=Producción, 2=Habilitación) desde una URL del portal DIAN.
function deriveAmbiente(url) {
  const u = String(url || '');
  if (/catalogo-vpfe-hab\.dian\.gov\.co/i.test(u)) return 2;
  if (/catalogo-vpfe\.dian\.gov\.co/i.test(u)) return 1;
  return 2; // por defecto: habilitación
}

// Pide los XML de una lista de CUFEs al API externo y los parsea.
// Devuelve { documents, ok, fail }.
async function fetchXmlsByCufes({ cufes = [], certificate, password, ambiente = 2, log = () => {} }) {
  const list = (Array.isArray(cufes) ? cufes : [])
    .map(c => String(c || '').trim())
    .filter(Boolean);

  if (list.length === 0) return { documents: [], ok: 0, fail: 0 };

  if (!XML_QUERY_API_URL) {
    log('❌ XML_QUERY_API_URL no está configurada en el .env del extractor');
    return { documents: [], ok: 0, fail: list.length };
  }

  let resp;
  try {
    resp = await axios.post(
      XML_QUERY_API_URL,
      { certificate, password, ambiente, cufes: list },
      {
        headers: { 'X-Api-Key': XML_QUERY_API_KEY, 'Content-Type': 'application/json' },
        timeout: 300000,
      }
    );
  } catch (err) {
    const msg = err.response ? `HTTP ${err.response.status}` : err.message;
    log(`❌ Error consultando el API de XML: ${msg}`);
    return { documents: [], ok: 0, fail: list.length };
  }

  const data = (resp.data && Array.isArray(resp.data.data)) ? resp.data.data : [];
  const documents = [];
  let ok = 0;
  let fail = 0;

  for (const item of data) {
    if (item && item.success && item.xmlBase64) {
      try {
        const xml = Buffer.from(item.xmlBase64, 'base64').toString('utf8');
        const doc = await parseUblXml(xml);
        if (doc) { documents.push(doc); ok++; } else { fail++; }
      } catch (_) {
        fail++;
      }
    } else {
      fail++;
    }
  }

  return { documents, ok, fail };
}

module.exports = { deriveAmbiente, fetchXmlsByCufes };
