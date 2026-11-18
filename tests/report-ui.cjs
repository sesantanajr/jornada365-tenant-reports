const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const assets = path.join(__dirname, '..', 'wordpress', 'jornada365-tenant-portal', 'assets', 'js');
const core = fs.readFileSync(path.join(assets, 'core.js'), 'utf8');
const portal = fs.readFileSync(path.join(assets, 'portal.js'), 'utf8');
const boot = '  if (!CFG.authenticated) renderLanding(';
assert.equal(portal.split(boot).length, 2);

class TestElement {
  constructor(tag, attrs, children) {
    this.tag = tag;
    this.attrs = attrs || {};
    this.children = [];
    this.classList = { add() {}, remove() {} };
    this.style = {};
    this.scrollLeft = 0;
    this.scrollTop = 0;
    this.append(...children);
  }
  set innerHTML(value) { this.children = []; }
  get firstChild() { return this.children[0]; }
  setAttribute(name, value) { this.attrs[name] = value; }
  removeAttribute(name) { delete this.attrs[name]; }
  querySelector() { return null; }
  focus() {}
  scrollIntoView() {}
  append(...children) { this.children.push(...children.flat().filter((child) => child !== null && child !== undefined)); }
  replaceChildren(...children) {
    this.children = [];
    this.scrollLeft = this.scrollTop = 0;
    this.append(...children);
  }
}

function environment(fetch, runBoot = false, putTimer = setTimeout) {
  const root = new TestElement('root', null, []);
  const context = {
    window: { crypto: require('node:crypto').webcrypto, J365TP: { restUrl: 'https://example.test/', authenticated: runBoot } },
    document: {
      cookie: '', documentElement: { dataset: {} }, querySelector: () => null,
      getElementById: () => root, createTextNode: (text) => text, addEventListener() {},
    },
    Node: TestElement, location: { search: '' }, URLSearchParams, fetch,
    AbortController, setTimeout: putTimer, clearTimeout,
  };
  vm.runInNewContext(core, context);
  const J = context.window.J365;
  J.h = (tag, attrs, ...children) => new TestElement(tag, attrs, children);
  J.svg = () => null;
  // Expose private interfaces to the test without booting the full account page.
  const source = runBoot ? portal : portal.slice(0, portal.indexOf(boot)) + '  window.testUi = { DataTable, fetchReport, followJob, reportPanel };\n})();';
  vm.runInNewContext(source, context);
  return { root, ...J, ...context.window.testUi };
}

const json = (data, status = 200, retry = null) => ({
  status, ok: status < 400, headers: { get: () => retry }, json: async () => data,
});

async function main() {
  let requestSignal;
  const stalled = environment((url, options) => {
    requestSignal = options.signal;
    return Promise.resolve({ ...json(null), json: () => new Promise(() => {}) });
  });
  await assert.rejects(stalled.api('me', { timeout: 5 }), (error) => error.status === 408);
  assert.equal(requestSignal.aborted, true);

  const retryable = environment(() => Promise.resolve(json({ message: 'Throttled' }, 429, '171')));
  await assert.rejects(retryable.api('me'), (error) => error.status === 429 && error.retryAfter === 171);
  const bootResult = environment(() => Promise.resolve(json(null, 502)), true);
  await new Promise((resolve) => setImmediate(resolve));
  const errorView = bootResult.root.children[0];
  assert.match(errorView.attrs.class, /boot-error/);
  assert.equal(errorView.children[1].children[0], 'Tentar novamente');

  const trace = [];
  const delays = [];
  let poll = 0;
  const longReport = environment(async (url) => {
    trace.push(new URL(url).pathname);
    if (url.includes('reports/')) return json({ job: { job_id: 'same', status: 'pending', progress: 0 } }, 202);
    if (url.endsWith('/result')) return json({ rows: [{ id: 1 }] });
    if (poll++ === 0) return json({ job_id: 'same', status: 'running', progress: 12, retryAfter: 171 });
    return json({ job_id: 'same', status: 'done', progress: 12 });
  }, false, (fn, ms) => {
    delays.push(ms);
    return setTimeout(fn, ms === 171000 ? 0 : 500);
  });
  const operatorA = new AbortController();
  const operatorB = new AbortController();
  const first = longReport.fetchReport('devices', {}, false, () => {}, operatorA.signal);
  const second = longReport.fetchReport('devices', {}, false, () => {}, operatorB.signal);
  operatorA.abort();
  await assert.rejects(first, (error) => error.name === 'AbortError');
  assert.equal((await second).rows[0].id, 1);
  assert.deepEqual(trace, ['/reports/devices', '/jobs/same/run', '/jobs/same/run', '/jobs/same/result']);
  assert.ok(delays.includes(171000));

  const starts = [];
  let steps = 0;
  const recovered = environment(async (url, req) => {
    if (url.includes('reports/')) {
      starts.push(JSON.parse(req.body));
      if (starts.length === 1) throw new TypeError('connection reset after insert');
      return json({ job: { job_id: 'restored', status: 'pending' } });
    }
    if (url.endsWith('/run')) {
      if (++steps === 1) return { ...json(null), json: async () => { throw new SyntaxError('truncated JSON'); } };
      return json({ job_id: 'restored', status: 'done' });
    }
    return json({ rows: [] });
  }, false, (fn, ms) => setTimeout(fn, ms < 2000 ? 0 : 1000));
  const seen = [];
  await recovered.fetchReport('groups', {}, false, (job) => seen.push(job.message), new AbortController().signal);
  assert.equal(starts.length, 2);
  assert.match(starts[0].request_id, /^[\da-f-]{36}$/);
  assert.equal(starts[0].request_id, starts[1].request_id);
  assert.equal(steps, 2);
  assert.ok(seen.includes('Nova tentativa em 2 s…'));

  let deniedStarts = 0;
  const denied = environment(async () => { deniedStarts++; return json({ message: 'Forbidden' }, 403); });
  await assert.rejects(denied.fetchReport('groups', {}, false, () => {}, new AbortController().signal), (e) => e.status === 403);
  assert.equal(deniedStarts, 1);

  const nextTick = () => new Promise((resolve) => setImmediate(resolve));
  let served = 0;
  const refreshed = environment(async (url) => {
    if (url.endsWith('/result')) return json({ _meta: { generated_at: '2026-03-01T09:00:00Z' }, rows: ['cached'] });
    if (served++) return json({ message: 'Denied' }, 403);
    return json({ job: { job_id: 'first', status: 'done' } });
  });
  const panel = refreshed.reportPanel('groups', {}, 'Grupos', (p) => p.rows.join(' '));
  await nextTick();
  const [hero, , feedback, reportBody] = panel.children;
  assert.equal(reportBody.children[0], 'cached');
  const refresh = hero.children.find((el) => el.attrs?.class === 'rp-tools').children.find((el) => el?.attrs?.title);
  await refresh.attrs.onclick();
  assert.equal(reportBody.children[0], 'cached');
  assert.equal(feedback.hidden, false);
  assert.equal(feedback.children[0].children[0], 'Denied');

  let deliveries = 0;
  const lostResult = environment(() => {
    deliveries++;
    return Promise.resolve(json({ message: 'Gateway timeout' }, 504));
  });
  await assert.rejects(lostResult.followJob({ job_id: 'finished', status: 'done' }), (error) => error.status === 504);
  assert.equal(deliveries, 1);

  let sortKeys = 0;
  const rows = Array.from({ length: 10000 }, (_, id) => ({ rank: (id * 101) % 10000 }));
  const renderedTable = longReport.DataTable({
    rows, name: 'test', columns: [{ key: 'rank', label: 'Pontuação', sortValue: (row) => { sortKeys++; return row.rank; } }],
  });
  const viewport = renderedTable.children.find((item) => item.attrs.class === 'tablewrap');
  assert.equal(viewport.attrs.tabindex, '0');
  assert.match(renderedTable.attrs.style, /--table-min: 145px/);
  viewport.scrollLeft = 90;
  const header = viewport.children[0].children[0].children[0].children[0];
  assert.equal(header.children[0].tag, 'button');
  header.children[0].attrs.onclick();
  assert.equal(viewport.children[0].children[0].children[0].children[0].attrs['aria-sort'], 'ascending');
  assert.equal(sortKeys, rows.length);
  assert.equal(viewport.scrollLeft, 90);
  const firstSortedCell = viewport.children[0].children[1].children[0].children[0];
  assert.equal(firstSortedCell.textContent, '0');
  renderedTable.setSearch('missing');
  const empty = renderedTable.children.find((item) => item.attrs.class === 'dt-empty');
  assert.equal(empty.hidden, false);
  assert.equal(empty.children[0].children[0], 'Nenhum resultado para os filtros atuais.');
  empty.children[1].attrs.onclick();
  assert.equal(empty.hidden, true);
  assert.equal(renderedTable.currentRows().length, rows.length);

  console.log('Report UI checks passed (idempotent startup retry, malformed polls, permissions, refresh recovery, shared cancellation, sortable and filterable 10,000-row table).');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
