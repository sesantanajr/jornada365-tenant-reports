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
    this.scrollLeft = 0;
    this.scrollTop = 0;
    this.append(...children);
  }
  set innerHTML(value) { this.children = []; }
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
    window: { J365TP: { restUrl: 'https://example.test/', authenticated: runBoot } },
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
  const source = runBoot ? portal : portal.slice(0, portal.indexOf(boot)) + '  window.testUi = { DataTable, fetchReport, followJob };\n})();';
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
  header.attrs.onclick();
  assert.equal(sortKeys, rows.length);
  assert.equal(viewport.scrollLeft, 90);
  const firstSortedCell = viewport.children[0].children[1].children[0].children[0];
  assert.equal(firstSortedCell.textContent, '0');

  console.log('Report UI checks passed (request deadline, retry headers, bootstrap recovery, shared polling/cancellation, 10,000-row table).');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
