const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const assets = path.join(__dirname, '..', 'wordpress', 'jornada365-tenant-portal', 'assets', 'js');
const key = 'j365.auth.v1';

function browser(fetch) {
  const cfg = { authenticated: true, tenancy: 'per_tenant', restUrl: '/portal/', spa: { authority: 'https://login.example.test', scopes: ['graph'], exoScopes: ['exo'] } };
  const data = new Map();
  const storage = { getItem: (k) => data.get(k), setItem: (k, v) => data.set(k, v), removeItem: (k) => data.delete(k) };
  storage.setItem(key, JSON.stringify({ tid: 'test', clientId: 'client', rt: 'fixture', account: { oid: 'user' }, tokens: {} }));
  const window = { J365TP: cfg, sessionStorage: storage };
  const context = { window, document: { cookie: '', documentElement: { dataset: {} }, querySelector: () => null, addEventListener() {} },
    fetch, URLSearchParams, AbortController, setTimeout, clearTimeout };
  for (const file of ['core.js', 'auth.js']) vm.runInNewContext(fs.readFileSync(path.join(assets, file), 'utf8'), context);
  return { api: window.J365.api, auth: window.J365Auth, storage };
}

const response = (status, body) => ({ status, ok: status < 400, headers: { get: () => null }, json: async () => body });

async function main() {
  const trace = [];
  let calls = 0;
  const tab = browser(async (url, opts) => {
    trace.push(url);
    if (url.endsWith('/token')) {
      if (!calls++) return response(400, { error: 'temporarily_unavailable' });
      return response(200, { access_token: 'graph-fresh', expires_in: 3600 });
    }
    assert.equal(opts.headers['X-J365-Graph'], 'graph-fresh');
    assert.equal(opts.headers['X-J365-Exo'], undefined);
    return response(200, { rows: [] });
  });
  const st = JSON.parse(tab.storage.getItem(key));
  st.tokens.exo = { at: 'expired', exp: Date.now() - 1000 };
  tab.storage.setItem(key, JSON.stringify(st));
  await assert.rejects(tab.api('me'), (e) => e.status === 503 && !e.reauth);
  assert.equal(trace.length, 1, 'No tokenless request or new sign-in after a transient error.');
  await tab.api('me');
  assert.equal(trace.length, 3, 'One token refresh and one authenticated Graph-only portal request.');

  const grants = [];
  const parallel = browser(async (url, opts) => {
    const resource = opts.body.get('scope').includes('exo') ? 'exo' : 'graph';
    return new Promise((resolve) => grants.push(() => resolve(response(200, { access_token: resource, expires_in: 3600 }))));
  });
  const graph = parallel.auth.token('graph'), exchange = parallel.auth.token('exo');
  grants[1](); await exchange;
  grants[0](); await graph;
  const headers = await parallel.auth.headers(true);
  assert.equal(headers['X-J365-Graph'], 'graph');
  assert.equal(headers['X-J365-Exo'], 'exo');
  assert.equal(grants.length, 2, 'Both resource tokens survived concurrent refresh.');

  const expired = browser(async () => response(400, { error: 'invalid_grant' }));
  await assert.rejects(expired.auth.token(), (e) => e.reauth && e.code === 'invalid_grant');
  console.log('Delegated auth checks passed (temporary outage, scope-specific refresh, concurrent tokens and expired grant).');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
