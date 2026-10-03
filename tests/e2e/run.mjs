// Testes ponta a ponta no navegador (Fase 18) — também geram os vídeos de demonstração.
//
//   BASE_URL=http://127.0.0.1:8080 node tests/e2e/run.mjs            # todos os cenários
//   node tests/e2e/run.mjs recepcao medico                           # só alguns
//   RECORD=dist/videos SLOW=350 node tests/e2e/run.mjs               # grava vídeo de cada cenário
//
// Pré-requisito: banco com o DemoSeeder (dados fictícios) e o servidor rodando.
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { scenarios } from './scenarios.mjs';

const require = createRequire(import.meta.url);
let playwright;
try { playwright = require('playwright'); } catch { playwright = require(execSync('npm root -g').toString().trim() + '/playwright'); }

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const RECORD = process.env.RECORD || '';
const SLOW = Number(process.env.SLOW || 0);
const only = process.argv.slice(2);

const browser = await playwright.chromium.launch({ slowMo: SLOW, executablePath: process.env.CHROMIUM_PATH || undefined });
let failed = 0;
for (const [name, fn] of Object.entries(scenarios)) {
  if (only.length && !only.includes(name)) continue;
  if (RECORD) mkdirSync(RECORD, { recursive: true });
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 820 }, locale: 'pt-BR', timezoneId: 'America/Sao_Paulo',
    ...(RECORD ? { recordVideo: { dir: RECORD, size: { width: 1366, height: 820 } } } : {}) });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => { errors.push(e.message); if (process.env.DEBUG) console.log('   pageerror', page.url(), e.message); });
  page.on('response', r => { if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.url()}`); });
  const started = Date.now();
  try {
    await fn(page, { base: BASE, slow: SLOW });
    if (errors.length) throw new Error('Erros na página: ' + errors.join(' | '));
    console.log(`✔ ${name} (${((Date.now() - started) / 1000).toFixed(1)} s)`);
  } catch (e) {
    failed++;
    console.log(`✘ ${name}: ${e.message.split('\n').slice(0, 4).join(' | ')}`);
    await page.screenshot({ path: `${RECORD || '/tmp'}/falha-${name}.png`, fullPage: true }).catch(() => {});
  }
  const video = page.video();
  await ctx.close();
  if (video) console.log(`  vídeo: ${await video.path()} → ${name}`);
}
await browser.close();
process.exit(failed ? 1 : 0);
