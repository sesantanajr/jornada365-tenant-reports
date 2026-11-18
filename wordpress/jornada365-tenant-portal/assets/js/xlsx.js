/*
 * Tenant Jornada365 — dependency-free Excel (.xlsx) writer.
 *
 * Builds Office Open XML SpreadsheetML in the browser: shared strings, styles, Excel tables (filter
 * buttons + banded rows), frozen panes, merged cells, conditional formatting (data bars, colour
 * scales, value rules), internal hyperlinks, native charts (bar, column, doughnut), pictures (PNG/JPEG,
 * e.g. the brand logo), gradient fills and print titles. Nothing is
 * sent anywhere: the workbook is zipped in memory (deflate via CompressionStream when available)
 * and handed to the browser as a download. No third-party code, no CDN.
 */
(function (global) {
  'use strict';

  const XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
  const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
  const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const NS_PR = 'http://schemas.openxmlformats.org/package/2006/relationships';
  const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';
  const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
  const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
  const REL = (t) => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/' + t;
  const CT = {
    sheet: 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml',
    table: 'application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml',
    drawing: 'application/vnd.openxmlformats-officedocument.drawing+xml',
    chart: 'application/vnd.openxmlformats-officedocument.drawingml.chart+xml',
  };
  const EMU = 9525; // per pixel at 96 dpi
  const MAX_TEXT = 32767;

  /* -------------------------------------------------------------- helpers */
  // Characters XML 1.0 forbids (and lone surrogates) are dropped; markup characters are escaped.
  const clean = (s) => String(s).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F￾￿]/g, '').replace(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(^|[^\uD800-\uDBFF])[\uDC00-\uDFFF]/g, '$1');
  const esc = (s) => clean(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const colName = (c) => { let s = '', n = c + 1; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; };
  const ref = (r, c) => colName(c) + (r + 1);
  const rng = (r1, c1, r2, c2) => (r1 === r2 && c1 === c2 ? ref(r1, c1) : ref(r1, c1) + ':' + ref(r2, c2));
  const quoteSheet = (n) => "'" + String(n).replace(/'/g, "''") + "'";
  const absRange = (sheet, r1, c1, r2, c2) => `${quoteSheet(sheet)}!$${colName(c1)}$${r1 + 1}:$${colName(c2)}$${r2 + 1}`;
  const hex = (c) => String(c || '').replace('#', '').toUpperCase().slice(0, 6);
  const num = (v) => (Number.isFinite(v) ? (Number.isInteger(v) ? String(v) : String(+v.toPrecision(15))) : '0');
  /** Excel serial date (local wall-clock time, 1900 date system). */
  const serial = (d) => (d.getTime() - d.getTimezoneOffset() * 60000) / 86400000 + 25569;

  /* ----------------------------------------------------------------- zip */
  const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
  const crc32 = (b) => { let c = 0xFFFFFFFF; for (let i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 0xFF] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; };
  async function deflate(bytes) {
    if (typeof CompressionStream === 'undefined') return null;
    try {
      const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('deflate-raw'));
      return new Uint8Array(await new Response(stream).arrayBuffer());
    } catch (e) { return null; }
  }
  async function zip(files) {
    const enc = new TextEncoder();
    const out = [], central = [];
    let offset = 0;
    const d = new Date();
    const time = ((d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1)) & 0xFFFF;
    const date = (((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate()) & 0xFFFF;
    for (const f of files) {
      const name = enc.encode(f.name);
      const raw = typeof f.data === 'string' ? enc.encode(f.data) : f.data;
      const crc = crc32(raw);
      let method = 0, body = raw;
      if (raw.length > 512) { const z = await deflate(raw); if (z && z.length < raw.length) { method = 8; body = z; } }
      const lh = new DataView(new ArrayBuffer(30));
      lh.setUint32(0, 0x04034b50, true); lh.setUint16(4, 20, true); lh.setUint16(6, 0x0800, true); lh.setUint16(8, method, true);
      lh.setUint16(10, time, true); lh.setUint16(12, date, true); lh.setUint32(14, crc, true); lh.setUint32(18, body.length, true);
      lh.setUint32(22, raw.length, true); lh.setUint16(26, name.length, true); lh.setUint16(28, 0, true);
      out.push(new Uint8Array(lh.buffer), name, body);
      const ch = new DataView(new ArrayBuffer(46));
      ch.setUint32(0, 0x02014b50, true); ch.setUint16(4, 20, true); ch.setUint16(6, 20, true); ch.setUint16(8, 0x0800, true); ch.setUint16(10, method, true);
      ch.setUint16(12, time, true); ch.setUint16(14, date, true); ch.setUint32(16, crc, true); ch.setUint32(20, body.length, true); ch.setUint32(24, raw.length, true);
      ch.setUint16(28, name.length, true); ch.setUint32(42, offset, true);
      central.push(new Uint8Array(ch.buffer), name);
      offset += 30 + name.length + body.length;
    }
    const size = central.reduce((a, b) => a + b.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true); end.setUint16(8, files.length, true); end.setUint16(10, files.length, true); end.setUint32(12, size, true); end.setUint32(16, offset, true);
    return new Blob([...out, ...central, new Uint8Array(end.buffer)], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }

  /* -------------------------------------------------------------- styles */
  const BUILTIN_FMT = { General: 0, '0': 1, '0.00': 2, '#,##0': 3, '#,##0.00': 4, '0%': 9, '0.00%': 10 };
  class Styles {
    constructor(font) {
      this.base = font || 'Calibri';
      this.fonts = []; this.fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
      this.borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>']; this.fmts = []; this.xfs = []; this.dxfs = []; this.memo = new Map();
      this.font({});
      this.xfs.push('<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>');
    }
    static idx(list, xml) { let i = list.indexOf(xml); if (i < 0) { list.push(xml); i = list.length - 1; } return i; }
    font({ b, i, u, sz = 10.5, color, name }) {
      return Styles.idx(this.fonts, `<font>${b ? '<b/>' : ''}${i ? '<i/>' : ''}${u ? '<u/>' : ''}<sz val="${sz}"/><color rgb="FF${hex(color || '0B2440')}"/><name val="${esc(name || this.base)}"/><family val="2"/></font>`);
    }
    /** color: 'RRGGBB' (solid) or { grad: ['RRGGBB', 'RRGGBB', …], deg } (linear gradient, left → right by default). */
    fill(color) {
      if (!color) return 0;
      if (typeof color === 'object' && Array.isArray(color.grad) && color.grad.length > 1) {
        const n = color.grad.length - 1;
        return Styles.idx(this.fills, `<fill><gradientFill degree="${+color.deg || 0}">${color.grad.map((c, i) => `<stop position="${num(i / n)}"><color rgb="FF${hex(c)}"/></stop>`).join('')}</gradientFill></fill>`);
      }
      return Styles.idx(this.fills, `<fill><patternFill patternType="solid"><fgColor rgb="FF${hex(color)}"/><bgColor indexed="64"/></patternFill></fill>`);
    }
    border(b) {
      if (!b) return 0;
      const side = (tag, s) => (s ? `<${tag} style="${s.style || 'thin'}"><color rgb="FF${hex(s.color || 'D9E2EC')}"/></${tag}>` : `<${tag}/>`);
      return Styles.idx(this.borders, `<border>${side('left', b.left)}${side('right', b.right)}${side('top', b.top)}${side('bottom', b.bottom)}<diagonal/></border>`);
    }
    fmt(code) {
      if (!code) return 0;
      if (code in BUILTIN_FMT) return BUILTIN_FMT[code];
      let f = this.fmts.find((x) => x.code === code);
      if (!f) { f = { id: 164 + this.fmts.length, code }; this.fmts.push(f); }
      return f.id;
    }
    /** spec: { font: {b,i,u,sz,color}, fill, border, fmt, h, v, wrap, indent } → cellXfs index */
    xf(spec = {}) {
      const key = JSON.stringify(spec);
      if (this.memo.has(key)) return this.memo.get(key);
      const fontId = this.font(spec.font || {}), fillId = this.fill(spec.fill), borderId = this.border(spec.border), numFmtId = this.fmt(spec.fmt);
      const align = spec.h || spec.v || spec.wrap || spec.indent ? `<alignment${spec.h ? ` horizontal="${spec.h}"` : ''}${spec.v ? ` vertical="${spec.v}"` : ''}${spec.wrap ? ' wrapText="1"' : ''}${spec.indent ? ` indent="${spec.indent}"` : ''}/>` : '';
      const xml = `<xf numFmtId="${numFmtId}" fontId="${fontId}" fillId="${fillId}" borderId="${borderId}" xfId="0"${numFmtId ? ' applyNumberFormat="1"' : ''}${fontId ? ' applyFont="1"' : ''}${fillId ? ' applyFill="1"' : ''}${borderId ? ' applyBorder="1"' : ''}${align ? ' applyAlignment="1">' + align + '</xf>' : '/>'}`;
      const id = Styles.idx(this.xfs, xml);
      this.memo.set(key, id);
      return id;
    }
    /** Same style flagged "quote prefix": the text is shown as typed and never re-read as a formula when edited. */
    quoted(i) {
      const key = 'q' + i;
      if (this.memo.has(key)) return this.memo.get(key);
      const id = Styles.idx(this.xfs, this.xfs[i].replace('<xf ', '<xf quotePrefix="1" '));
      this.memo.set(key, id);
      return id;
    }
    /** Differential format for conditional formatting: { color, b, fill } → dxf index */
    dxf({ color, b, fill }) {
      return Styles.idx(this.dxfs, `<dxf>${color || b ? `<font>${b ? '<b/>' : ''}${color ? `<color rgb="FF${hex(color)}"/>` : ''}</font>` : ''}${fill ? `<fill><patternFill patternType="solid"><fgColor rgb="FF${hex(fill)}"/><bgColor rgb="FF${hex(fill)}"/></patternFill></fill>` : ''}</dxf>`);
    }
    xml() {
      return XML + `<styleSheet xmlns="${NS_MAIN}">`
        + (this.fmts.length ? `<numFmts count="${this.fmts.length}">${this.fmts.map((f) => `<numFmt numFmtId="${f.id}" formatCode="${esc(f.code)}"/>`).join('')}</numFmts>` : '')
        + `<fonts count="${this.fonts.length}">${this.fonts.join('')}</fonts>`
        + `<fills count="${this.fills.length}">${this.fills.join('')}</fills>`
        + `<borders count="${this.borders.length}">${this.borders.join('')}</borders>`
        + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        + `<cellXfs count="${this.xfs.length}">${this.xfs.join('')}</cellXfs>`
        + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        + `<dxfs count="${this.dxfs.length}">${this.dxfs.join('')}</dxfs>`
        + '<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
        + '</styleSheet>';
    }
  }

  /* --------------------------------------------------------------- sheet */
  class Sheet {
    constructor(book, name, opts = {}) {
      this.book = book; this.name = name; this.opts = opts;
      this.cells = new Map(); this.widths = {}; this.heights = {}; this.merges = [];
      this.cfs = []; this.tables = []; this.charts = []; this.pics = []; this.links = []; this.pane = null; this.titles = null; this.maxR = 0; this.maxC = 0;
    }
    /** v: number | string | boolean | Date | null | { f: 'FORMULA', v?: cached }; style: cellXfs index */
    set(r, c, v, style = 0) {
      if (!this.cells.has(r)) this.cells.set(r, new Map());
      this.cells.get(r).set(c, { v, s: style });
      if (r > this.maxR) this.maxR = r;
      if (c > this.maxC) this.maxC = c;
      return this;
    }
    width(c, w) { this.widths[c] = w; return this; }
    height(r, h) { this.heights[r] = h; return this; }
    merge(r1, c1, r2, c2) { if (r2 > r1 || c2 > c1) this.merges.push(rng(r1, c1, r2, c2)); return this; }
    freeze(r, c = 0) { this.pane = { r, c }; return this; }
    /** Internal hyperlink to another sheet (cell A1, or `cell` such as 'B12'). */
    link(r, c, sheetName, display, cell = 'A1') { this.links.push({ ref: ref(r, c), location: quoteSheet(sheetName) + '!' + (/^[A-Z]{1,3}\d{1,7}$/.test(cell) ? cell : 'A1'), display }); return this; }
    /** Rows repeated at the top of every printed page (e.g. the table header). */
    printTitles(r1, r2 = r1) { this.titles = [r1, r2]; return this; }
    /**
     * Picture over the grid. img: from Workbook.image(); at: { col, row, dx, dy } (px offsets inside the cell);
     * size: [width, height] in px (aspect ratio is the caller's choice; Workbook.image keeps the natural size).
     */
    picture(img, { col = 0, row = 0, dx = 0, dy = 0, width, height, name = 'Logo', descr = '' } = {}) {
      if (!img) return this;
      let w = +width || 0, h0 = +height || 0;
      if (!w && !h0) { w = img.width; h0 = img.height; } else if (!w) w = img.width * (h0 / img.height); else if (!h0) h0 = img.height * (w / img.width);
      this.pics.push({ img, col, row, dx: Math.round(dx), dy: Math.round(dy), w: Math.max(1, Math.round(w)), h: Math.max(1, Math.round(h0)), name, descr });
      return this;
    }
    /** Data bar on a numeric range (min..max automatic unless given). */
    dataBar(r1, c1, r2, c2, color = '2B8EF0', min = null, max = null) {
      const cfvo = (t, v) => (v === null ? `<cfvo type="${t}"/>` : `<cfvo type="num" val="${num(v)}"/>`);
      this.cfs.push({ sqref: rng(r1, c1, r2, c2), rule: (p) => `<cfRule type="dataBar" priority="${p}"><dataBar>${cfvo('min', min)}${cfvo('max', max)}<color rgb="FF${hex(color)}"/></dataBar></cfRule>` });
      return this;
    }
    /** 3-colour scale (low → high). */
    scale(r1, c1, r2, c2, colors = ['63BE7B', 'FFEB84', 'F8696B']) {
      this.cfs.push({ sqref: rng(r1, c1, r2, c2), rule: (p) => `<cfRule type="colorScale" priority="${p}"><colorScale><cfvo type="min"/><cfvo type="percentile" val="50"/><cfvo type="max"/>${colors.map((x) => `<color rgb="FF${hex(x)}"/>`).join('')}</colorScale></cfRule>` });
      return this;
    }
    /** Highlights cells whose value equals a text (or number). */
    whenEquals(r1, c1, r2, c2, value, dxfId) {
      if (typeof value !== 'number' && String(value).length > 255) return this; // Excel: text constants in formulas ≤ 255 chars
      const lit = typeof value === 'number' ? num(value) : '"' + esc(String(value).replace(/"/g, '""')) + '"';
      this.cfs.push({ sqref: rng(r1, c1, r2, c2), rule: (p) => `<cfRule type="cellIs" dxfId="${dxfId}" priority="${p}" operator="equal"><formula>${lit}</formula></cfRule>` });
      return this;
    }
    /** Highlights numbers ≥ a threshold. */
    whenAtLeast(r1, c1, r2, c2, value, dxfId) {
      this.cfs.push({ sqref: rng(r1, c1, r2, c2), rule: (p) => `<cfRule type="cellIs" dxfId="${dxfId}" priority="${p}" operator="greaterThanOrEqual"><formula>${num(value)}</formula></cfRule>` });
      return this;
    }
    /**
     * Excel table at (r, c): headers + rows (arrays). Returns { r1, c1, r2, c2 }.
     * colStyles[i]: cellXfs index for data cells of column i.
     */
    table(r, c, headers, rows, { name, style = 'TableStyleMedium2', colStyles = [], headerStyle = 0 } = {}) {
      const seen = new Set();
      const heads = headers.map((h0, i) => {
        let hName = clean(String(h0 || '').replace(/[\r\n]+/g, ' ')).trim() || 'Coluna ' + (i + 1);
        const base = hName; let k = 2;
        while (seen.has(hName.toLowerCase())) hName = `${base} (${k++})`;
        seen.add(hName.toLowerCase());
        return hName;
      });
      heads.forEach((hh, i) => this.set(r, c + i, hh, headerStyle));
      const body = rows.length ? rows : [heads.map(() => null)];
      body.forEach((row, ri) => heads.forEach((_, i) => this.set(r + 1 + ri, c + i, row[i] === undefined ? null : row[i], colStyles[i] || 0)));
      const box = { r1: r, c1: c, r2: r + body.length, c2: c + heads.length - 1 };
      this.tables.push({ id: ++this.book.tableSeq, name: this.book.tableName(name), ref: rng(box.r1, box.c1, box.r2, box.c2), heads, style });
      return box;
    }
    /** Native chart anchored over cells. spec: { type: 'bar'|'col'|'doughnut', title, cats: [r1,c1,r2,c2], vals: [...], labels, values, colors, from: [c,r], to: [c,r], fmt } */
    chart(spec) { this.charts.push(Object.assign({ id: ++this.book.chartSeq }, spec)); return this; }

    xml(sst) {
      const o = this.opts;
      const parts = [XML, `<worksheet xmlns="${NS_MAIN}" xmlns:r="${NS_R}">`];
      parts.push(`<sheetPr>${o.tab ? `<tabColor rgb="FF${hex(o.tab)}"/>` : ''}<pageSetUpPr fitToPage="1"/></sheetPr>`);
      parts.push(`<dimension ref="${rng(0, 0, Math.max(0, this.maxR), Math.max(0, this.maxC))}"/>`);
      let view = `<sheetView workbookViewId="0"${o.grid === false ? ' showGridLines="0"' : ''}${o.selected ? ' tabSelected="1"' : ''}${o.zoom ? ` zoomScale="${o.zoom}" zoomScaleNormal="${o.zoom}"` : ''}>`;
      if (this.pane && (this.pane.r || this.pane.c)) {
        const tl = ref(this.pane.r, this.pane.c);
        const pn = this.pane.r && this.pane.c ? 'bottomRight' : this.pane.r ? 'bottomLeft' : 'topRight';
        view += `<pane${this.pane.c ? ` xSplit="${this.pane.c}"` : ''}${this.pane.r ? ` ySplit="${this.pane.r}"` : ''} topLeftCell="${tl}" activePane="${pn}" state="frozen"/><selection pane="${pn}" activeCell="${tl}" sqref="${tl}"/>`;
      } else view += '<selection activeCell="A1" sqref="A1"/>';
      parts.push(`<sheetViews>${view}</sheetView></sheetViews>`);
      parts.push('<sheetFormatPr defaultRowHeight="15"/>');
      const cols = Object.keys(this.widths).map(Number).sort((a, b) => a - b);
      if (cols.length) parts.push(`<cols>${cols.map((c) => `<col min="${c + 1}" max="${c + 1}" width="${num(Math.max(1, Math.min(120, this.widths[c])))}" customWidth="1"/>`).join('')}</cols>`);
      parts.push('<sheetData>');
      const rows = [...new Set([...this.cells.keys(), ...Object.keys(this.heights).map(Number)])].sort((a, b) => a - b);
      for (const r of rows) {
        const ht = this.heights[r];
        const row = this.cells.get(r);
        let xml = `<row r="${r + 1}"${ht ? ` ht="${num(ht)}" customHeight="1"` : ''}>`;
        if (row) {
          for (const c of [...row.keys()].sort((a, b) => a - b)) {
            const { v, s } = row.get(c);
            const at = `r="${ref(r, c)}"${s ? ` s="${s}"` : ''}`;
            if (v === null || v === undefined || v === '') xml += `<c ${at}/>`;
            else if (typeof v === 'number') xml += Number.isFinite(v) ? `<c ${at}><v>${num(v)}</v></c>` : `<c ${at}/>`;
            else if (typeof v === 'boolean') xml += `<c ${at} t="b"><v>${v ? 1 : 0}</v></c>`;
            else if (v instanceof Date) xml += isNaN(v.getTime()) ? `<c ${at}/>` : `<c ${at}><v>${num(serial(v))}</v></c>`;
            else if (typeof v === 'object' && v.f) xml += `<c ${at}><f>${esc(v.f)}</f>${v.v !== undefined ? `<v>${num(+v.v)}</v>` : ''}</c>`;
            else {
              // Tenant-controlled text that looks like a formula (= + - @) stays text even if someone edits the cell.
              const txt = String(v);
              const q = /^[=+\-@]/.test(txt) ? ` s="${this.book.styles.quoted(s || 0)}"` : (s ? ` s="${s}"` : '');
              xml += `<c r="${ref(r, c)}"${q} t="s"><v>${sst.add(txt)}</v></c>`;
            }
          }
        }
        parts.push(xml + '</row>');
      }
      parts.push('</sheetData>');
      if (this.merges.length) parts.push(`<mergeCells count="${this.merges.length}">${this.merges.map((m) => `<mergeCell ref="${m}"/>`).join('')}</mergeCells>`);
      let prio = 1;
      for (const cf of this.cfs) parts.push(`<conditionalFormatting sqref="${cf.sqref}">${cf.rule(prio++)}</conditionalFormatting>`);
      if (this.links.length) parts.push(`<hyperlinks>${this.links.map((l) => `<hyperlink ref="${l.ref}" location="${esc(l.location)}"${l.display ? ` display="${esc(l.display)}"` : ''}/>`).join('')}</hyperlinks>`);
      parts.push('<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>');
      parts.push(`<pageSetup orientation="${o.portrait ? 'portrait' : 'landscape'}" fitToWidth="1" fitToHeight="0"/>`);
      if (o.footer || o.header) parts.push(`<headerFooter>${o.header ? `<oddHeader>${esc(o.header)}</oddHeader>` : ''}${o.footer ? `<oddFooter>${esc(o.footer)}</oddFooter>` : ''}</headerFooter>`);
      if (this.charts.length || this.pics.length) parts.push('<drawing r:id="rIdDr"/>');
      if (this.tables.length) parts.push(`<tableParts count="${this.tables.length}">${this.tables.map((t, i) => `<tablePart r:id="rIdT${i + 1}"/>`).join('')}</tableParts>`);
      parts.push('</worksheet>');
      return parts.join('');
    }
  }

  /* --------------------------------------------------------------- charts */
  function chartXml(ch, sheetName, lang) {
    const rich = (txt, sz, b) => `<c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="${sz}" b="${b ? 1 : 0}"/></a:pPr><a:r><a:rPr lang="${lang}" sz="${sz}" b="${b ? 1 : 0}"><a:solidFill><a:srgbClr val="0B2440"/></a:solidFill></a:rPr><a:t>${esc(txt)}</a:t></a:r></a:p></c:rich>`;
    const txPr = (sz, color = '3B5573') => `<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="${sz}"><a:solidFill><a:srgbClr val="${color}"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="${lang}"/></a:p></c:txPr>`;
    const strCache = (labels) => `<c:strCache><c:ptCount val="${labels.length}"/>${labels.map((l, i) => `<c:pt idx="${i}"><c:v>${esc(l)}</c:v></c:pt>`).join('')}</c:strCache>`;
    const numCache = (vals) => `<c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="${vals.length}"/>${vals.map((v, i) => v == null ? '' : `<c:pt idx="${i}"><c:v>${num(+v || 0)}</c:v></c:pt>`).join('')}</c:numCache>`;
    const cat = `<c:cat><c:strRef><c:f>${esc(absRange(sheetName, ...ch.cats))}</c:f>${strCache(ch.labels)}</c:strRef></c:cat>`;
    const val = `<c:val><c:numRef><c:f>${esc(absRange(sheetName, ...ch.vals))}</c:f>${numCache(ch.values)}</c:numRef></c:val>`;
    const colors = ch.colors && ch.colors.length ? ch.colors : ['0072C6'];
    const fill = (c) => `<c:spPr><a:solidFill><a:srgbClr val="${hex(c)}"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>`;
    const dLbls = (pos, pct) => `<c:dLbls><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr>${txPr(900, pct ? 'FFFFFF' : '0B2440')}${pos ? `<c:dLblPos val="${pos}"/>` : ''}<c:showLegendKey val="0"/><c:showVal val="${pct ? 0 : 1}"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="${pct ? 1 : 0}"/><c:showBubbleSize val="0"/>${pct ? '<c:showLeaderLines val="0"/>' : ''}</c:dLbls>`;
    let plot;
    if (ch.type === 'doughnut') {
      const pts = ch.labels.map((_, i) => `<c:dPt><c:idx val="${i}"/><c:bubble3D val="0"/><c:spPr><a:solidFill><a:srgbClr val="${hex(colors[i % colors.length])}"/></a:solidFill><a:ln w="12700"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>`).join('');
      plot = `<c:doughnutChart><c:varyColors val="1"/><c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>${esc(ch.title || '')}</c:v></c:tx>${pts}${dLbls('', true)}${cat}${val}</c:ser><c:firstSliceAng val="0"/><c:holeSize val="58"/></c:doughnutChart>`;
    } else {
      const dir = ch.type === 'col' ? 'col' : 'bar';
      const many = colors.length > 1 && ch.labels.length > 1;
      const pts = many ? ch.labels.map((_, i) => `<c:dPt><c:idx val="${i}"/><c:invertIfNegative val="0"/><c:bubble3D val="0"/>${fill(colors[i % colors.length])}</c:dPt>`).join('') : '';
      plot = `<c:barChart><c:barDir val="${dir}"/><c:grouping val="clustered"/><c:varyColors val="0"/><c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>${esc(ch.title || '')}</c:v></c:tx>${fill(colors[0])}<c:invertIfNegative val="0"/>${pts}${dLbls('outEnd', false)}${cat}${val}</c:ser><c:gapWidth val="55"/><c:axId val="5001"/><c:axId val="5002"/></c:barChart>`
        + `<c:catAx><c:axId val="5001"/><c:scaling><c:orientation val="${dir === 'bar' ? 'maxMin' : 'minMax'}"/></c:scaling><c:delete val="0"/><c:axPos val="${dir === 'bar' ? 'l' : 'b'}"/><c:numFmt formatCode="General" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:spPr><a:ln><a:solidFill><a:srgbClr val="D9E2EC"/></a:solidFill></a:ln></c:spPr>${txPr(900)}<c:crossAx val="5002"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>`
        + `<c:valAx><c:axId val="5002"/><c:scaling><c:orientation val="minMax"/>${ch.percentScale ? '<c:max val="100"/><c:min val="0"/>' : ''}</c:scaling><c:delete val="1"/><c:axPos val="${dir === 'bar' ? 'b' : 'l'}"/><c:numFmt formatCode="${esc(ch.fmt || 'General')}" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="5001"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>`;
    }
    const legend = ch.type === 'doughnut' ? `<c:legend><c:legendPos val="r"/><c:overlay val="0"/>${txPr(900)}</c:legend>` : '';
    return XML + `<c:chartSpace xmlns:c="${NS_C}" xmlns:a="${NS_A}" xmlns:r="${NS_R}"><c:roundedCorners val="0"/><c:chart>`
      + (ch.title ? `<c:title><c:tx>${rich(ch.title, 1100, true)}</c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/>` : '<c:autoTitleDeleted val="1"/>')
      + `<c:plotArea><c:layout/>${plot}<c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr></c:plotArea>${legend}<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart>`
      + '<c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="9525"><a:solidFill><a:srgbClr val="DDE7F1"/></a:solidFill></a:ln></c:spPr>'
      + `<c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="900"/></a:pPr><a:endParaRPr lang="${lang}"/></a:p></c:txPr></c:chartSpace>`;
  }
  function drawingXml(charts, pics) {
    let id = 1;
    const anchors = charts.map((ch, i) => {
      const [c1, r1] = ch.from, [c2, r2] = ch.to;
      return `<xdr:twoCellAnchor editAs="oneCell"><xdr:from><xdr:col>${c1}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>${r1}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:to><xdr:col>${c2}</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>${r2}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>`
        + `<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="${++id}" name="${esc('Chart ' + (i + 1))}"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>`
        + `<a:graphic><a:graphicData uri="${NS_C}"><c:chart xmlns:c="${NS_C}" xmlns:r="${NS_R}" r:id="rIdC${i + 1}"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>`;
    }).join('') + pics.map((p, i) => {
      const cx = p.w * EMU, cy = p.h * EMU;
      return `<xdr:oneCellAnchor><xdr:from><xdr:col>${p.col}</xdr:col><xdr:colOff>${p.dx * EMU}</xdr:colOff><xdr:row>${p.row}</xdr:row><xdr:rowOff>${p.dy * EMU}</xdr:rowOff></xdr:from><xdr:ext cx="${cx}" cy="${cy}"/>`
        + `<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="${++id}" name="${esc(p.name)}" descr="${esc(p.descr || p.name)}"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>`
        + `<xdr:blipFill><a:blip r:embed="rIdI${i + 1}"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>`
        + `<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="${cx}" cy="${cy}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>`;
    }).join('');
    return XML + `<xdr:wsDr xmlns:xdr="${NS_XDR}" xmlns:a="${NS_A}" xmlns:r="${NS_R}">${anchors}</xdr:wsDr>`;
  }
  /** Natural size of a PNG (IHDR) or JPEG (SOFn); null when the bytes are neither. */
  function imageInfo(b) {
    if (!(b instanceof Uint8Array) || b.length < 24) return null;
    if (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47) {
      const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
      return { type: 'png', width: dv.getUint32(16), height: dv.getUint32(20) };
    }
    if (b[0] === 0xFF && b[1] === 0xD8) {
      let i = 2;
      while (i + 9 < b.length) {
        if (b[i] !== 0xFF) { i++; continue; }
        const m = b[i + 1], len = (b[i + 2] << 8) | b[i + 3];
        if (m >= 0xC0 && m <= 0xCF && ![0xC4, 0xC8, 0xCC].includes(m)) return { type: 'jpeg', height: (b[i + 5] << 8) | b[i + 6], width: (b[i + 7] << 8) | b[i + 8] };
        i += 2 + len;
      }
    }
    return null;
  }
  const rels = (list) => XML + `<Relationships xmlns="${NS_PR}">${list.map((r) => `<Relationship Id="${r.id}" Type="${r.type}" Target="${esc(r.target)}"/>`).join('')}</Relationships>`;

  /* ------------------------------------------------------------ workbook */
  class SharedStrings {
    constructor() { this.map = new Map(); this.list = []; this.count = 0; }
    add(s) {
      let v = clean(s);
      if (v.length > MAX_TEXT) v = v.slice(0, MAX_TEXT - 1) + '…';
      this.count++;
      if (!this.map.has(v)) { this.map.set(v, this.list.length); this.list.push(v); }
      return this.map.get(v);
    }
    xml() {
      return XML + `<sst xmlns="${NS_MAIN}" count="${this.count}" uniqueCount="${this.list.length}">${this.list.map((s) => `<si><t xml:space="preserve">${esc(s)}</t></si>`).join('')}</sst>`;
    }
  }

  class Workbook {
    constructor({ title = '', subject = '', creator = '', company = '', lang = 'pt-BR', font = 'Calibri' } = {}) {
      this.meta = { title, subject, creator, company, lang };
      this.styles = new Styles(font);
      this.sheets = []; this.tableSeq = 0; this.chartSeq = 0; this.tableNames = new Set(); this.media = [];
    }
    /** Excel sheet names: ≤ 31 chars, no []:*?/\ , unique (case-insensitive). */
    sheet(name, opts) {
      let base = clean(String(name || 'Planilha')).replace(/[[\]:*?/\\]/g, ' ').replace(/^'+|'+$/g, '').trim().slice(0, 31) || 'Planilha';
      let n = base, k = 2;
      while (this.sheets.some((s) => s.name.toLowerCase() === n.toLowerCase())) { const suf = ` (${k++})`; n = base.slice(0, 31 - suf.length) + suf; }
      const s = new Sheet(this, n, opts);
      this.sheets.push(s);
      return s;
    }
    tableName(hint) {
      let n = String(hint || 'Tabela').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z0-9_]/g, '_').replace(/^[^A-Za-z_]+/, '');
      n = ('T_' + (n || 'Tabela')).slice(0, 60);
      let out = n, k = 2;
      while (this.tableNames.has(out.toLowerCase())) out = `${n}_${k++}`;
      this.tableNames.add(out.toLowerCase());
      return out;
    }
    /** Registers a PNG/JPEG for pictures. Returns { width, height, … } or null when the bytes are not an image. */
    image(bytes) {
      const b = bytes instanceof Uint8Array ? bytes : (bytes instanceof ArrayBuffer ? new Uint8Array(bytes) : null);
      const info = b && imageInfo(b);
      if (!info || !info.width || !info.height) return null;
      let m = this.media.find((x) => x.bytes === b);
      if (!m) { m = { n: this.media.length + 1, bytes: b, ext: info.type === 'jpeg' ? 'jpeg' : 'png', width: info.width, height: info.height }; this.media.push(m); }
      return m;
    }
    xf(spec) { return this.styles.xf(spec); }
    dxf(spec) { return this.styles.dxf(spec); }

    async blob() {
      const sst = new SharedStrings();
      const files = [];
      const types = [];
      const wbRels = [];
      let drawingSeq = 0;
      this.sheets.forEach((s, i) => {
        const n = i + 1;
        files.push({ name: `xl/worksheets/sheet${n}.xml`, data: s.xml(sst) });
        types.push(`<Override PartName="/xl/worksheets/sheet${n}.xml" ContentType="${CT.sheet}"/>`);
        wbRels.push({ id: 'rId' + n, type: REL('worksheet'), target: `worksheets/sheet${n}.xml` });
        const sRels = [];
        s.tables.forEach((t, k) => {
          files.push({ name: `xl/tables/table${t.id}.xml`, data: XML + `<table xmlns="${NS_MAIN}" id="${t.id}" name="${t.name}" displayName="${t.name}" ref="${t.ref}" totalsRowShown="0"><autoFilter ref="${t.ref}"/><tableColumns count="${t.heads.length}">${t.heads.map((h0, j) => `<tableColumn id="${j + 1}" name="${esc(h0)}"/>`).join('')}</tableColumns><tableStyleInfo name="${t.style}" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>` });
          types.push(`<Override PartName="/xl/tables/table${t.id}.xml" ContentType="${CT.table}"/>`);
          sRels.push({ id: 'rIdT' + (k + 1), type: REL('table'), target: `../tables/table${t.id}.xml` });
        });
        if (s.charts.length || s.pics.length) {
          const d = ++drawingSeq;
          files.push({ name: `xl/drawings/drawing${d}.xml`, data: drawingXml(s.charts, s.pics) });
          types.push(`<Override PartName="/xl/drawings/drawing${d}.xml" ContentType="${CT.drawing}"/>`);
          sRels.push({ id: 'rIdDr', type: REL('drawing'), target: `../drawings/drawing${d}.xml` });
          const dRels = [];
          s.charts.forEach((ch, k) => {
            files.push({ name: `xl/charts/chart${ch.id}.xml`, data: chartXml(ch, s.name, this.meta.lang) });
            types.push(`<Override PartName="/xl/charts/chart${ch.id}.xml" ContentType="${CT.chart}"/>`);
            dRels.push({ id: 'rIdC' + (k + 1), type: REL('chart'), target: `../charts/chart${ch.id}.xml` });
          });
          s.pics.forEach((p, k) => dRels.push({ id: 'rIdI' + (k + 1), type: REL('image'), target: `../media/image${p.img.n}.${p.img.ext}` }));
          files.push({ name: `xl/drawings/_rels/drawing${d}.xml.rels`, data: rels(dRels) });
        }
        if (sRels.length) files.push({ name: `xl/worksheets/_rels/sheet${n}.xml.rels`, data: rels(sRels) });
      });
      const k = this.sheets.length;
      wbRels.push({ id: 'rId' + (k + 1), type: REL('styles'), target: 'styles.xml' }, { id: 'rId' + (k + 2), type: REL('sharedStrings'), target: 'sharedStrings.xml' });
      for (const m of this.media) files.push({ name: `xl/media/image${m.n}.${m.ext}`, data: m.bytes });
      const active = Math.max(0, this.sheets.findIndex((s) => s.opts.selected));
      const names = this.sheets.map((s, i) => (s.titles ? `<definedName name="_xlnm.Print_Titles" localSheetId="${i}">${esc(quoteSheet(s.name))}!$${s.titles[0] + 1}:$${s.titles[1] + 1}</definedName>` : '')).join('');
      files.push({ name: 'xl/workbook.xml', data: XML + `<workbook xmlns="${NS_MAIN}" xmlns:r="${NS_R}"><workbookPr/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15600" activeTab="${active}"/></bookViews><sheets>${this.sheets.map((s, i) => `<sheet name="${esc(s.name)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets>${names ? `<definedNames>${names}</definedNames>` : ''}<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>` });
      files.push({ name: 'xl/_rels/workbook.xml.rels', data: rels(wbRels) });
      files.push({ name: 'xl/styles.xml', data: this.styles.xml() });
      files.push({ name: 'xl/sharedStrings.xml', data: sst.xml() });
      const now = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
      files.push({ name: 'docProps/core.xml', data: XML + `<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>${esc(this.meta.title)}</dc:title><dc:subject>${esc(this.meta.subject)}</dc:subject><dc:creator>${esc(this.meta.creator)}</dc:creator><cp:lastModifiedBy>${esc(this.meta.creator)}</cp:lastModifiedBy><dc:language>${esc(this.meta.lang)}</dc:language><dcterms:created xsi:type="dcterms:W3CDTF">${now}</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">${now}</dcterms:modified></cp:coreProperties>` });
      files.push({ name: 'docProps/app.xml', data: XML + `<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Tenant Jornada365</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><Company>${esc(this.meta.company)}</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion></Properties>` });
      files.push({ name: '_rels/.rels', data: rels([{ id: 'rId1', type: REL('officeDocument'), target: 'xl/workbook.xml' }, { id: 'rId2', type: 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', target: 'docProps/core.xml' }, { id: 'rId3', type: REL('extended-properties'), target: 'docProps/app.xml' }]) });
      const exts = [...new Set(this.media.map((m) => m.ext))].map((e) => `<Default Extension="${e}" ContentType="image/${e}"/>`).join('');
      const ctypes = XML + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' + exts
        + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        + types.join('')
        + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        + '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        + '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
      return zip([{ name: '[Content_Types].xml', data: ctypes }, ...files]);
    }

    async download(filename) {
      const blob = await this.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = filename; a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
      return blob;
    }
  }

  global.J365Xlsx = { Workbook, colName, ref, rng, serial, imageInfo };
})(typeof window !== 'undefined' ? window : globalThis);
