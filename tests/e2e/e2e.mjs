import Pusher from 'pusher-js';
import WebSocket from 'ws';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';

// Smoke test against a running `php artisan reverb:start` (127.0.0.1:8080).
// Uses the first two apps in ../../.env REVERB_APPS (add a throwaway second app
// with `php artisan reverb:add-app other-app --origins=other.test` for the
// cross-app checks). Run: cd tests/e2e && npm i && node e2e.mjs
import { readFileSync } from 'node:fs';
const env = Object.fromEntries(readFileSync(new URL('../../.env', import.meta.url), 'utf8')
  .split('\n').map((l) => l.match(/^([A-Z0-9_]+)=(.*)$/)).filter(Boolean).map(([, k, v]) => [k, v.replace(/^"|"$/g, '')]));
const app = (name) => {
  const p = `REVERB_APP_${name.toUpperCase().replaceAll('-', '_')}_`;
  return { name, id: env[p + 'ID'], key: env[p + 'KEY'], secret: env[p + 'SECRET'], origin: env[p + 'ORIGINS'].split(',')[0] };
};
const [A, B] = env.REVERB_APPS.split(',').filter(Boolean).map(app);
if (!B) { console.error('Need two apps in REVERB_APPS for the cross-app checks.'); process.exit(1); }
const opts = (app) => ({
  wsHost: '127.0.0.1', wsPort: 8080, forceTLS: false, enabledTransports: ['ws'], cluster: '',
  // stands in for the app backend's /broadcasting/auth endpoint
  channelAuthorization: { customHandler: ({ socketId, channelName }, cb) =>
    cb(null, { auth: `${app.key}:` + crypto.createHmac('sha256', app.secret).update(`${socketId}:${channelName}`).digest('hex') }) },
});
const results = {};
const check = (name, ok) => { results[name] = ok; console.log(ok ? 'PASS' : 'FAIL', name); };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// raw handshake with a given Origin / key → first pusher event
const raw = (key, origin) => new Promise((res) => {
  const ws = new WebSocket(`ws://127.0.0.1:8080/app/${key}?protocol=7&client=js&version=8`, origin ? { origin } : {});
  ws.on('message', (d) => { res(JSON.parse(d)); ws.close(); });
  ws.on('error', (e) => res({ error: e.message }));
});

const a = new Pusher(A.key, opts(A));
const b = new Pusher(B.key, opts(B));
const got = { aPub: null, aPriv: null, b: null };
a.subscribe('orders').bind('OrderPaid', (d) => (got.aPub = d));
a.subscribe('private-user.1').bind('OrderPaid', (d) => (got.aPriv = d));
b.subscribe('orders').bind('OrderPaid', (d) => (got.b = d));
await wait(1500);
check('client A connected (no Origin header, like native SDKs)', a.connection.state === 'connected');
check('client B connected on other app', b.connection.state === 'connected');
check('private channel auth accepted', a.channel('private-user.1').subscribed);

const pub = (app, ch, msg) => execFileSync('php', [new URL('./publish.php', import.meta.url).pathname, app.id, app.key, app.secret, ch, msg]).toString();
pub(A, 'orders', 'hello-public');
pub(A, 'private-user.1', 'hello-private');
await wait(1000);
check('public event arrived', got.aPub?.msg === 'hello-public');
check('private event arrived', got.aPriv?.msg === 'hello-private');
check(`${B.name} did NOT see ${A.name} event`, got.b === null);

let badPub = false; try { pub({ ...A, secret: 'wrong' }, 'orders', 'x'); } catch { badPub = true; }
check('publish with wrong secret rejected', badPub);

const ok = await raw(A.key, `https://${A.origin.replace('*.', 'x.')}`);
check('allowed browser Origin accepted', ok.event === 'pusher:connection_established');
const evil = await raw(A.key, 'https://evil.com');
check('foreign browser Origin rejected', evil.event === 'pusher:error' && JSON.parse(evil.data).code === 4009);
const cross = await raw(A.key, `https://${B.origin.replace('*.', 'x.')}`);
check(`${B.name}'s Origin rejected for ${A.name}`, cross.event === 'pusher:error');
const badKey = await raw('nope', null);
check('unknown key rejected', badKey.event === 'pusher:error' && JSON.parse(badKey.data).code === 4001);

a.disconnect(); b.disconnect();
process.exit(Object.values(results).every(Boolean) ? 0 : 1);
