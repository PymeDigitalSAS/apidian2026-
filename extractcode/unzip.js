// unzip.js
const fs = require('fs');
const path = require('path');
const AdmZip = require('adm-zip');
const xml2js = require('xml2js');
const XLSX = require('xlsx');
const logger = require('./logger');
const fsp = require('fs').promises;

const downloadDir = path.resolve(__dirname, 'downloads');

// ----------------- Helpers -----------------
function pickText(node) {
  if (node == null) return null;
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (node._ != null) return String(node._);
  if (node['#text'] != null) return String(node['#text']);
  return null;
}
function get(obj, keys = []) {
  let cur = obj;
  for (const k of keys) {
    if (cur == null) return null;
    cur = cur[k];
  }
  return cur;
}
// Parser numérico robusto (respeta separador decimal)
function toNumber(val) {
  if (val == null || val === '') return 0;
  const s = String(val).trim();

  // Ambos separadores presentes
  if (s.includes('.') && s.includes(',')) {
    // Ej: 1.234.567,89  -> coma decimal
    if (s.lastIndexOf(',') > s.lastIndexOf('.')) {
      return parseFloat(s.replace(/\./g, '').replace(',', '.'));
    }
    // Ej: 1,234,567.89  -> punto decimal
    return parseFloat(s.replace(/,/g, ''));
  }

  // Solo coma -> coma decimal
  if (s.includes(',')) return parseFloat(s.replace(',', '.'));

  // Solo punto (o ninguno) -> punto decimal
  return parseFloat(s);
}
function asArray(v) {
  if (v == null) return [];
  return Array.isArray(v) ? v : [v];
}

// Party (NIT, nombre)
function extractPartyInfo(partyNode) {
  const taxSchemes = asArray(get(partyNode, ['cac:Party', 'cac:PartyTaxScheme']));
  const companyId = pickText(get(taxSchemes[0] || {}, ['cbc:CompanyID'])) || null;
  const name1 = pickText(get(partyNode, ['cac:Party', 'cac:PartyName', 'cbc:Name']));
  const name2 = pickText(get(partyNode, ['cac:Party', 'cac:PartyLegalEntity', 'cbc:RegistrationName']));
  const name = name1 || name2 || null;
  return { nit: companyId, name };
}

// Impuestos cabecera
function extractHeaderTaxes(root) {
  const taxTotals = asArray(get(root, ['cac:TaxTotal']));
  let totalHeaderTaxes = 0;
  const breakdown = [];
  for (const tt of taxTotals) {
    const amount = toNumber(pickText(get(tt, ['cbc:TaxAmount'])));
    totalHeaderTaxes += amount;
    const subs = asArray(get(tt, ['cac:TaxSubtotal']));
    for (const s of subs) {
      breakdown.push({
        code: pickText(get(s, ['cac:TaxCategory', 'cac:TaxScheme', 'cbc:ID'])) || null,
        name: pickText(get(s, ['cac:TaxCategory', 'cac:TaxScheme', 'cbc:Name'])) || null,
        percent: toNumber(pickText(get(s, ['cac:TaxCategory', 'cbc:Percent']))),
        taxable_amount: toNumber(pickText(get(s, ['cbc:TaxableAmount']))),
        amount: toNumber(pickText(get(s, ['cbc:TaxAmount']))),
      });
    }
  }
  return { totalHeaderTaxes, breakdown };
}

// Impuestos de línea
function extractLineTaxes(lineNode) {
  const taxTotals = asArray(get(lineNode, ['cac:TaxTotal']));
  const taxes = [];
  let totalTaxLine = 0;
  for (const tt of taxTotals) {
    const subs = asArray(get(tt, ['cac:TaxSubtotal']));
    for (const s of subs) {
      const amount = toNumber(pickText(get(s, ['cbc:TaxAmount'])));
      taxes.push({
        code: pickText(get(s, ['cac:TaxCategory', 'cac:TaxScheme', 'cbc:ID'])) || null,
        name: pickText(get(s, ['cac:TaxCategory', 'cac:TaxScheme', 'cbc:Name'])) || null,
        percent: toNumber(pickText(get(s, ['cac:TaxCategory', 'cbc:Percent']))),
        taxable_amount: toNumber(pickText(get(s, ['cbc:TaxableAmount']))),
        amount,
      });
      totalTaxLine += amount;
    }
  }
  return { taxes, totalTaxLine };
}

// Líneas (Invoice/CreditNote/DebitNote)
function extractLines(root, docType) {
  const lineKey =
    docType === 'CreditNote' ? 'cac:CreditNoteLine'
    : docType === 'DebitNote' ? 'cac:DebitNoteLine'
    : 'cac:InvoiceLine';

  const lines = asArray(get(root, [lineKey]));
  const normalized = [];

  for (const ln of lines) {
    const qtyNode =
      get(ln, ['cbc:InvoicedQuantity']) ||
      get(ln, ['cbc:CreditedQuantity']) ||
      get(ln, ['cbc:DebitedQuantity']);
    const qty = toNumber(pickText(qtyNode));
    const uom =
      (qtyNode && qtyNode.$ && (qtyNode.$.unitCode || qtyNode.$.unitcode)) ||
      null;

    const priceNode = get(ln, ['cac:Price', 'cbc:PriceAmount']);
    const unitPrice = toNumber(pickText(priceNode));

    const lineExt = toNumber(pickText(get(ln, ['cbc:LineExtensionAmount'])));

    const itemName =
      pickText(get(ln, ['cac:Item', 'cbc:Name'])) ||
      pickText(get(ln, ['cac:Item', 'cbc:Description'])) ||
      null;

    const sellersItemId = pickText(get(ln, ['cac:Item', 'cac:SellersItemIdentification', 'cbc:ID']));
    const stdItemId = pickText(get(ln, ['cac:Item', 'cac:StandardItemIdentification', 'cbc:ID']));
    const productRef = sellersItemId || stdItemId || null;

    const { taxes, totalTaxLine } = extractLineTaxes(ln);

    normalized.push({
      'Nombre del Producto': itemName,
      'Referencia': productRef,
      'Unidad de Medida': uom,        // alias: "Medida"
      'Cantidad': qty,
      'Precio Unitario': unitPrice,   // alias tolerado en Odoo: "UnitPrice"
      'Total': lineExt || qty * unitPrice || 0,
      taxes,
      total_tax_line: totalTaxLine,
    });
  }
  return normalized;
}

// Parseo principal de un XML UBL
async function parseUblXml(xmlText, type_Search) {
  const parser = new xml2js.Parser({ explicitArray: false, ignoreAttrs: false, mergeAttrs: false });
  const json = await parser.parseStringPromise(xmlText);

  const root = json['Invoice'] || json['CreditNote'] || json['DebitNote'];
  const docType = json['CreditNote'] ? 'CreditNote' : json['DebitNote'] ? 'DebitNote' : 'Invoice';
  if (!root) return null;

  const number = pickText(get(root, ['cbc:ID']));
  const issueDate = pickText(get(root, ['cbc:IssueDate']));
  const dueDate = pickText(get(root, ['cbc:DueDate']));
  const currency = pickText(get(root, ['cbc:DocumentCurrencyCode']));
  const uuid = pickText(get(root, ['cbc:UUID'])); // CUFE

  // Extraer prefijo autorizado desde DianExtensions
  let prefix = null;
  try {
    const exts = asArray(get(root, ['ext:UBLExtensions', 'ext:UBLExtension']));
    for (const ext of exts) {
      const dianExt = get(ext, ['ext:ExtensionContent', 'sts:DianExtensions']);
      if (dianExt) {
        prefix = pickText(get(dianExt, ['sts:InvoiceControl', 'sts:AuthorizedInvoices', 'sts:Prefix'])) || null;
        break;
      }
    }
  } catch (_) { /* sin prefijo */ }

  const supplier = extractPartyInfo(get(root, ['cac:AccountingSupplierParty']) || {});
  const customer = extractPartyInfo(get(root, ['cac:AccountingCustomerParty']) || {});

  const pm = asArray(get(root, ['cac:PaymentMeans']))[0] || {};
  const paymentMeansId = pickText(get(pm, ['cbc:ID']));           // 1=contado, 2=crédito
  const paymentMeansCode = pickText(get(pm, ['cbc:PaymentMeansCode']));
  const paymentDueDate = pickText(get(pm, ['cbc:PaymentDueDate']));

  const lmt = get(root, ['cac:LegalMonetaryTotal']) || {};
  const lineExtensionAmount = toNumber(pickText(get(lmt, ['cbc:LineExtensionAmount'])));
  const taxExclusiveAmount = toNumber(pickText(get(lmt, ['cbc:TaxExclusiveAmount'])));
  const taxInclusiveAmount = toNumber(pickText(get(lmt, ['cbc:TaxInclusiveAmount'])));
  const payableAmount = toNumber(pickText(get(lmt, ['cbc:PayableAmount'])));

  const { totalHeaderTaxes, breakdown } = extractHeaderTaxes(root);
  const lines = extractLines(root, docType);

  // Retenciones (heurística simple)
  const withholdings = breakdown
    .filter(tx => (tx.name || '').toLowerCase().includes('reten') || ['06','07','08'].includes(String(tx.code)))
    .reduce((acc, tx) => acc + (tx.amount || 0), 0);

  const out = {
    'TipoDocumento': docType,
    'TipoBusqueda': type_Search || null,
    'Prefijo': prefix || null,
    'Numero de Factura': number || null,
    'CUFE': uuid || null,
    'Fecha de Creación': issueDate || null,
    'Fecha de Vencimiento': dueDate || paymentDueDate || null,
    'Moneda': currency || null,
    'NIT del Emisor': supplier.nit || null,
    'Razon Social del Emisor': supplier.name || null,
    'NIT del Receptor': customer.nit || null,
    'Razon Social del Receptor': customer.name || null,
    'Metodo de Pago': paymentMeansId || null,
    'Codigo Medio de Pago': paymentMeansCode || null,
    'Sub Total': lineExtensionAmount,
    'Impuestos Cabecera': totalHeaderTaxes,
    'Retenciones': withholdings,
    'Total a Pagar': payableAmount || taxInclusiveAmount || taxExclusiveAmount,
    'TaxBreakdown': breakdown,
    'LineItems': lines, // <-- detalle de cada línea
  };
  return out;
}

// Escribir salidas (JSON + 2 hojas Excel) en la carpeta de la sesión
async function writeOutputs(documents, outputDir) {
  const baseDir = outputDir || downloadDir;
  try { await fsp.mkdir(baseDir, { recursive: true }); } catch (e) {}

  const datetime = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const outputJson = path.join(baseDir, `resultado_${datetime}.json`);
  const outputExcel = path.join(baseDir, `resultado_${datetime}.xlsx`);

  // JSON
  await fsp.writeFile(outputJson, JSON.stringify(documents, null, 2), 'utf8');

  // Hoja 1: Documentos (resumen)
  const resumen = documents.map(d => ({
    TipoDocumento: d['TipoDocumento'] || '',
    Numero: d['Numero de Factura'] || '',
    CUFE: d['CUFE'] || '',
    Fecha: d['Fecha de Creación'] || '',
    Vencimiento: d['Fecha de Vencimiento'] || '',
    Moneda: d['Moneda'] || '',
    NIT_Emisor: d['NIT del Emisor'] || '',
    Nombre_Emisor: d['Razon Social del Emisor'] || '',
    NIT_Receptor: d['NIT del Receptor'] || '',
    Nombre_Receptor: d['Razon Social del Receptor'] || '',
    MetodoPago: d['Metodo de Pago'] || '',
    Subtotal: d['Sub Total'] || 0,
    ImpuestosCabecera: d['Impuestos Cabecera'] || 0,
    Retenciones: d['Retenciones'] || 0,
    TotalPagar: d['Total a Pagar'] || 0,
    Lineas: Array.isArray(d['LineItems']) ? d['LineItems'].length : 0,
  }));

  // Hoja 2: Lineas (detalle por producto)
  const filasLineas = [];
  for (const d of documents) {
    const cufe = d['CUFE'] || '';
    const numero = d['Numero de Factura'] || '';
    const tipo = d['TipoDocumento'] || '';
    const fecha = d['Fecha de Creación'] || '';
    const nitEmi = d['NIT del Emisor'] || '';
    const nitRec = d['NIT del Receptor'] || '';
    (d['LineItems'] || []).forEach((ln, idx) => {
      const taxes = ln.taxes && ln.taxes.length ? ln.taxes : [null];
      taxes.forEach(tx => {
        filasLineas.push({
          TipoDocumento: tipo,
          Numero: numero,
          CUFE: cufe,
          Fecha: fecha,
          NIT_Emisor: nitEmi,
          NIT_Receptor: nitRec,
          Linea: idx + 1,
          Nombre_Producto: ln['Nombre del Producto'] || '',
          Referencia: ln['Referencia'] || '',
          Medida: ln['Unidad de Medida'] || '',
          Cantidad: ln['Cantidad'] || 0,
          PrecioUnitario: ln['Precio Unitario'] || 0,
          TotalLinea: ln['Total'] || 0,
          ImpCode: tx ? (tx.code || '') : '',
          ImpName: tx ? (tx.name || '') : '',
          ImpPercent: tx ? (tx.percent || 0) : 0,
          ImpBase: tx ? (tx.taxable_amount || 0) : 0,
          ImpValor: tx ? (tx.amount || 0) : 0,
        });
      });
    });
  }

  const wb = XLSX.utils.book_new();
  const wsDocs = XLSX.utils.json_to_sheet(resumen);
  const wsLines = XLSX.utils.json_to_sheet(filasLineas);
  XLSX.utils.book_append_sheet(wb, wsDocs, 'Documentos');
  XLSX.utils.book_append_sheet(wb, wsLines, 'Lineas');
  XLSX.writeFile(wb, outputExcel);

  logger.emit('log', `✅ JSON generado: ${outputJson}`);
  logger.emit('log', `✅ Excel generado: ${outputExcel}`);

  // >>> Devuelve ambas rutas (cambio solicitado)
  return { outputJson, outputExcel };
}

// --------------- API principal ----------------
async function processFolder(downloadPath, type_Search) {
  try {
    const files = fs.readdirSync(downloadPath).filter(f => f.toLowerCase().endsWith('.zip'));
    if (files.length === 0) {
      logger.emit('log', '⚠️ No se encontraron archivos .zip en la carpeta de descargas');
      return null;
    }

    const documents = [];
    let zipsOk = 0;
    let zipsFail = 0;

    for (const z of files) {
      const zipPath = path.join(downloadPath, z);

      // Verificar existencia con reintentos: en Windows hay un breve momento
      // tras la descarga donde readdirSync ve el archivo pero existsSync aún no.
      let exists = false;
      for (let attempt = 0; attempt < 6; attempt++) {
        if (fs.existsSync(zipPath)) { exists = true; break; }
        await new Promise(r => setTimeout(r, 250));
      }
      if (!exists) {
        zipsFail++;
        logger.emit('log', `⚠️ ZIP no accesible (skip): ${z}`);
        continue;
      }

      // Aislar cada ZIP en su propio try — un archivo corrupto NO debe abortar
      // el batch completo. El Excel/JSON saldrá igual con el resto.
      try {
        const stat = fs.statSync(zipPath);
        if (stat.size === 0) {
          zipsFail++;
          logger.emit('log', `⚠️ ZIP vacío (0 bytes), skip: ${z}`);
          continue;
        }

        const zip = new AdmZip(zipPath);
        const entries = zip.getEntries();
        let xmlsEnEsteZip = 0;

        for (const entry of entries) {
          if (!entry.entryName.toLowerCase().endsWith('.xml')) continue;
          xmlsEnEsteZip++;
          let xmlContent;
          try {
            xmlContent = zip.readAsText(entry);
          } catch (e) {
            logger.emit('log', `❌ No se pudo leer ${entry.entryName} de ${z}: ${e.message}`);
            continue;
          }
          try {
            const doc = await parseUblXml(xmlContent, type_Search);
            if (doc) {
              documents.push(doc);
            } else {
              logger.emit('log', `⚠️ XML omitido (no es Invoice/CreditNote/DebitNote): ${entry.entryName}`);
            }
          } catch (e) {
            logger.emit('log', `❌ Error parseando ${entry.entryName}: ${e.message}`);
          }
        }
        zipsOk++;
        if (xmlsEnEsteZip === 0) {
          logger.emit('log', `⚠️ ZIP sin XML adentro: ${z}`);
        }
      } catch (zipErr) {
        zipsFail++;
        logger.emit('log', `❌ ZIP corrupto o ilegible (skip): ${z} — ${zipErr.message}`);
      }
    }

    if (zipsFail > 0) {
      logger.emit('log', `📊 Resumen ZIPs: ${zipsOk} OK, ${zipsFail} fallaron`);
    }

    if (documents.length === 0) {
      logger.emit('log', '⚠️ No se encontraron XML UBL válidos en los ZIPs');
      return null;
    }

    const { outputJson, outputExcel } = await writeOutputs(documents, downloadPath);

    logger.emit('log', `JSON_PATH:${path.basename(outputJson)}`);
    logger.emit('log', `EXCEL_PATH:${path.basename(outputExcel)}`);

    return outputExcel;
  } catch (err) {
    logger.emit('log', `❌ Error en processFolder: ${err.message}`);
    return null;
  }
}

// --------------- Extraer CUFEs de ZIPs  ----------------
async function extractCufes(folderPath) {
  const targetPath = folderPath || downloadDir;
  const files = fs.readdirSync(targetPath).filter(f => f.toLowerCase().endsWith('.zip'));

  if (files.length === 0) {
    return { success: false, message: 'No se encontraron archivos .zip', data: [] };
  }

  const parser = new xml2js.Parser({ explicitArray: false, ignoreAttrs: false, mergeAttrs: false });
  const results = [];

  for (const z of files) {
    const zipPath = path.join(targetPath, z);
    try {
      const zip = new AdmZip(zipPath);
      const entries = zip.getEntries();

      for (const entry of entries) {
        if (!entry.entryName.toLowerCase().endsWith('.xml')) continue;
        const xmlContent = zip.readAsText(entry);
        try {
          const json = await parser.parseStringPromise(xmlContent);
          const root = json['Invoice'] || json['CreditNote'] || json['DebitNote'];
          if (!root) continue;

          const docType = json['CreditNote'] ? 'CreditNote' : json['DebitNote'] ? 'DebitNote' : 'Invoice';
          const cufe = pickText(get(root, ['cbc:UUID'])) || null;
          const numero = pickText(get(root, ['cbc:ID'])) || null;
          const fechaEmision = pickText(get(root, ['cbc:IssueDate'])) || null;

          const supplier = extractPartyInfo(get(root, ['cac:AccountingSupplierParty']) || {});
          const customer = extractPartyInfo(get(root, ['cac:AccountingCustomerParty']) || {});

          const lmt = get(root, ['cac:LegalMonetaryTotal']) || {};
          const total = toNumber(pickText(get(lmt, ['cbc:PayableAmount'])));

          const pm = asArray(get(root, ['cac:PaymentMeans']))[0] || {};
          const medioPago = pickText(get(pm, ['cbc:PaymentMeansCode'])) || null;

          results.push({
            zipFile: z,
            xmlFile: entry.entryName,
            tipoDocumento: docType,
            cufe,
            numero,
            fechaEmision,
            nitEmisor: supplier.nit,
            nombreEmisor: supplier.name,
            nitReceptor: customer.nit,
            nombreReceptor: customer.name,
            total,
            medioPago,
            xmlBase64: Buffer.from(xmlContent, 'utf8').toString('base64'),
          });
        } catch (e) {
          // XML no parseable, saltar
        }
      }
    } catch (e) {
      // ZIP corrupto, saltar
    }
  }

  return {
    success: true,
    message: `Se extrajeron ${results.length} documento(s) de ${files.length} ZIP(s)`,
    data: results,
  };
}

// Escribe el resultado del modo CUFE (JSON + Excel de 1 hoja) en la carpeta de la
// sesión. El JSON se llama resultado_<fecha>.json para que readSessionResult
// (server.js) lo devuelva como `data` de la API. Emite EXCEL_PATH para que la UI
// ofrezca la descarga, igual que el flujo de ZIPs.
async function writeCufesResult(registros, outputDir) {
  const baseDir = outputDir || downloadDir;
  try { await fsp.mkdir(baseDir, { recursive: true }); } catch (e) {}

  const datetime = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const outputJson = path.join(baseDir, `resultado_${datetime}.json`);
  const outputExcel = path.join(baseDir, `resultado_${datetime}.xlsx`);

  await fsp.writeFile(outputJson, JSON.stringify(registros, null, 2), 'utf8');

  try {
    const ws = XLSX.utils.json_to_sheet(registros);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'CUFEs');
    XLSX.writeFile(wb, outputExcel);
    logger.emit('log', `EXCEL_PATH:${path.basename(outputExcel)}`);
  } catch (e) {
    logger.emit('log', `⚠️ No se pudo generar el Excel de CUFEs: ${e.message}`);
  }

  return { json: outputJson, excel: outputExcel, count: registros.length };
}

module.exports = { processFolder, extractCufes, writeCufesResult, parseUblXml, writeOutputs };