// Teste de carga simples (Fase 18): N requisições por tela com C simultâneas, usuário logado.
//   BASE_URL=http://127.0.0.1:8080 N=60 C=8 node tests/load/load.mjs
// Mede p50/p95/máx e erros. Use em homologação (mesmo servidor/plano da produção) — o servidor
// embutido do PHP (php artisan serve) é só referência e é bem mais lento que LiteSpeed/PHP-FPM.
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const N = Number(process.env.N || 60), C = Number(process.env.C || 8);
const EMAIL = process.env.EMAIL || 'admin@demo.aivexa.local', PASS = process.env.PASS || 'Demo@12345';
const PAGES = (process.env.PAGES || '/,/agenda,/pacientes,/contas-a-receber,/conversas,/relatorios/appointments,/fila').split(',');

let cookies = {};
const jar = () => Object.entries(cookies).map(([k, v]) => `${k}=${v}`).join('; ');
const keep = (res) => { for (const c of res.headers.getSetCookie?.() || []) { const [kv] = c.split(';'); const i = kv.indexOf('='); cookies[kv.slice(0, i)] = kv.slice(i + 1); } };

async function login() {
  const r1 = await fetch(BASE + '/login', { redirect: 'manual' }); keep(r1);
  const token = (await r1.text()).match(/name="_token" value="([^"]+)"/)?.[1];
  const body = new URLSearchParams({ _token: token, email: EMAIL, password: PASS });
  const r2 = await fetch(BASE + '/login', { method: 'POST', body, redirect: 'manual', headers: { Cookie: jar(), 'Content-Type': 'application/x-www-form-urlencoded' } });
  keep(r2);
  if (r2.status !== 302) throw new Error('Login falhou: HTTP ' + r2.status);
}

const pct = (a, p) => a[Math.min(a.length - 1, Math.floor(a.length * p))];

await login();
console.log(`Carga: ${N} requisições por tela, ${C} simultâneas — ${BASE}\n`);
console.log('Tela'.padEnd(28) + 'p50 ms'.padStart(8) + 'p95 ms'.padStart(8) + 'máx ms'.padStart(8) + 'req/s'.padStart(8) + 'erros'.padStart(7));
let worst = 0, errorsTotal = 0;
for (const page of PAGES) {
  const times = []; let errors = 0, next = 0; const t0 = Date.now();
  await Promise.all(Array.from({ length: C }, async () => {
    while (next < N) {
      next++;
      const s = performance.now();
      try {
        const r = await fetch(BASE + page, { headers: { Cookie: jar() }, redirect: 'manual' });
        await r.arrayBuffer();
        if (r.status !== 200) errors++;
      } catch { errors++; }
      times.push(performance.now() - s);
    }
  }));
  times.sort((a, b) => a - b);
  const rps = N / ((Date.now() - t0) / 1000);
  worst = Math.max(worst, pct(times, 0.95)); errorsTotal += errors;
  console.log(page.padEnd(28) + pct(times, 0.5).toFixed(0).padStart(8) + pct(times, 0.95).toFixed(0).padStart(8) + times[times.length - 1].toFixed(0).padStart(8) + rps.toFixed(1).padStart(8) + String(errors).padStart(7));
}
const limit = Number(process.env.P95_LIMIT_MS || 0);
if (errorsTotal || (limit && worst > limit)) { console.log(`\nFALHOU: ${errorsTotal} erro(s); pior p95 ${worst.toFixed(0)} ms`); process.exit(1); }
console.log(`\nOK — pior p95: ${worst.toFixed(0)} ms, sem erros.`);
