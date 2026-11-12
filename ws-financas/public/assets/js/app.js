// WS Finanças em Dia — painel principal.
// Mesmo visual e as mesmas regras do painel original; o armazenamento saiu do navegador (localStorage)
// e foi para o servidor (API /app/api). Permissões e isolamento são sempre conferidos no servidor.
(() => {
"use strict";
// ---------- utilidades ----------
const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const BRL = v => (Number(v)||0).toLocaleString("pt-BR",{style:"currency",currency:"BRL"});
const pad = n => String(n).padStart(2,"0");
const BOOT = JSON.parse(document.getElementById("app").dataset.boot || "{}");
const HOJE = BOOT.hoje || (() => { const h = new Date(); return `${h.getFullYear()}-${pad(h.getMonth()+1)}-${pad(h.getDate())}`; })();
const DIA_HOJE = +HOJE.slice(8,10);
const MES_ATUAL = HOJE.slice(0,7);
const MESES = ["janeiro","fevereiro","março","abril","maio","junho","julho","agosto","setembro","outubro","novembro","dezembro"];
const MESES_C = ["jan","fev","mar","abr","mai","jun","jul","ago","set","out","nov","dez"];
const addMes = (ym, n) => { let [y,m] = ym.split("-").map(Number); m += n; y += Math.floor((m-1)/12); m = ((m-1)%12+12)%12+1; return `${y}-${pad(m)}`; };
const nomeMes = ym => { const [y,m] = ym.split("-").map(Number); return `${MESES[m-1]} de ${y}`; };
const curtoMes = ym => { const [y,m] = ym.split("-").map(Number); return `${MESES_C[m-1]}/${y}`; };
const fmtData = iso => iso ? `${iso.slice(8,10)}/${iso.slice(5,7)}` : "";
const soma = arr => arr.reduce((a,x) => a + (Number(x.valor)||0), 0);
function parseValor(s){
  s = String(s||"").replace(/r\$\s*/i,"").trim();
  if (!s) return NaN;
  if (s.includes(",")) return parseFloat(s.replace(/\./g,"").replace(",","."));
  if (/^\d{1,3}(\.\d{3})+$/.test(s)) return parseFloat(s.replace(/\./g,""));
  return parseFloat(s);
}

const CATS = (BOOT.categorias && BOOT.categorias.despesa) || ["Outros"];
const CATS_R = (BOOT.categorias && BOOT.categorias.receita) || ["Outras entradas"];
// limite sugerido de cada categoria, em % da renda do mês
const REF = {"Moradia":30,"Contas da casa":10,"Alimentação":15,"Delivery e lanches":4,"Transporte":10,"Saúde":8,"Educação":6,"Assinaturas":2,"Lazer":5,"Compras":5,"Outros":5,
  "Fornecedores":40,"Folha de pagamento":35,"Impostos e taxas":12,"Aluguel comercial":12,"Serviços e manutenção":6,"Marketing":6,"Tarifas bancárias":2};
const DICAS = {
  "Moradia":"Moradia acima de 30% da renda aperta todo o resto. Vale conversar com o proprietário sobre o reajuste ou avaliar um imóvel mais barato quando o contrato vencer.",
  "Contas da casa":"Revise o plano de internet e celular (muitas vezes dá para baixar a franquia), desligue aparelhos em stand-by e confira se a família tem direito à Tarifa Social de energia.",
  "Alimentação":"Faça lista antes do mercado, compre em atacarejo os itens de maior volume e prefira feira no fim do horário, quando o preço cai.",
  "Delivery e lanches":"Delivery é o corte mais rápido. Cozinhar em casa três vezes por semana costuma reduzir esse gasto pela metade.",
  "Transporte":"Combine caronas, compare o aplicativo com ônibus/metrô nos trajetos fixos e mantenha a calibragem dos pneus para gastar menos combustível.",
  "Saúde":"Pergunte pelo genérico na farmácia e use programas de desconto como o Farmácia Popular quando o remédio estiver na lista.",
  "Educação":"Procure bolsas, descontos por pontualidade e material usado antes de comprar novo.",
  "Assinaturas":"Cancele as assinaturas que ninguém usou neste mês. Dá para assinar de novo depois que as dívidas acabarem.",
  "Lazer":"Troque parte das saídas pagas por programas gratuitos e defina um valor fixo de lazer no começo do mês.",
  "Compras":"Espere 48 horas antes de comprar o que não é essencial e evite parcelar compras novas enquanto houver dívida com juros.",
  "Outros":"Gastos em 'Outros' costumam esconder pequenos vazamentos. Vale dar nome a eles nos próximos lançamentos.",
  "Fornecedores":"Negocie prazo e desconto à vista com os principais fornecedores e compare pelo menos três cotações nas compras grandes.",
  "Folha de pagamento":"Folha alta para o faturamento pede revisão de escalas e horas extras antes de qualquer corte de pessoal.",
  "Impostos e taxas":"Converse com o contador sobre o regime tributário: a escolha certa (Simples, Lucro Presumido) pode reduzir bastante os impostos.",
  "Aluguel comercial":"Aluguel acima de 12% do faturamento costuma pesar; negocie o reajuste ou avalie um espaço menor.",
  "Serviços e manutenção":"Junte serviços recorrentes em contratos anuais e revise sistemas e assinaturas que a equipe não usa.",
  "Marketing":"Meça o retorno de cada canal e corte o que não traz cliente nos últimos três meses.",
  "Tarifas bancárias":"Compare pacotes de tarifa e taxas da maquininha; bancos digitais costumam cobrar bem menos."
};

// ---------- estado ----------
const S = { items:new Map(), mes:MES_ATUAL, tab:"mes", form:null, confirm:null, fb:null, divForm:null, ai:{state:"idle",text:""},
  empresa: (BOOT.empresas[0] || {}).id || "", carregando:true, erro:"", fluxo:null, rel:null, relDe:MES_ATUAL, relAte:MES_ATUAL, pend:false };
const LKEY = "wsfin:v1";
const consolidado = () => S.empresa === "todas";
const podeLancar = () => !!BOOT.podeLancar;
const contasDa = emp => (BOOT.contas || []).filter(c => c.empresa === emp);
const empresaNome = id => (BOOT.empresas.find(e => e.id === id) || {}).nome || "";
try { const e = localStorage.getItem("wsf:empresa"); if (e && (BOOT.empresas.some(x => x.id === e) || (e === "todas" && BOOT.consolidado))) S.empresa = e; } catch(e){}

async function api(method, url, body){
  const opt = { method, headers:{ "Accept":"application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content, "X-Requested-With":"XMLHttpRequest" }, credentials:"same-origin" };
  if (body !== undefined) { opt.headers["Content-Type"] = "application/json"; opt.body = JSON.stringify(body); }
  const r = await fetch(url, opt);
  if (r.status === 419 || r.status === 401) { location.reload(); throw new Error("Sessão expirada."); }
  const data = await r.json().catch(() => ({}));
  if (!r.ok) {
    const msg = data.message || (data.errors ? Object.values(data.errors).flat().join(" ") : "Não foi possível concluir agora. Tente de novo.");
    throw new Error(msg);
  }
  return data;
}
const qs = o => Object.entries(o).filter(([,v]) => v !== undefined && v !== null && v !== "").map(([k,v]) => `${k}=${encodeURIComponent(v)}`).join("&");

async function carregar(){
  try {
    const d = await api("GET", `/app/api/mes?${qs({ empresa:S.empresa, mes:S.mes })}`);
    const m = new Map(); d.itens.forEach(x => m.set(x.id, x)); S.items = m; S.erro = "";
  } catch(e) { S.erro = e.message; }
  S.carregando = false;
  if (S.tab === "fluxo") S.fluxo = null;
  render();
}
async function acao(fn, okMsg){
  try { await fn(); if (okMsg) S.fb = { ok:true, t:okMsg }; } catch(e) { S.fb = { ok:false, t:e.message }; aviso(e.message); }
  await carregar();
}
let loadNote = "";
function aviso(t){ loadNote = t; renderNotice(); setTimeout(() => { if (loadNote === t) { loadNote = ""; renderNotice(); } }, 8000); }

// ---------- consultas (iguais ao original) ----------
const todos = () => [...S.items.values()];
const despesas = () => todos().filter(x => x.tipo === "despesa");
const doMes = M => despesas().filter(d => d.mes === M).sort((a,b) => (a.venc||"").localeCompare(b.venc||""));
const atrasadas = M => despesas().filter(d => d.mes < M && !d.pago).sort((a,b) => (a.venc||"").localeCompare(b.venc||""));
const receitas = M => todos().filter(x => x.tipo === "receita" && x.mes === M);
const dividas = () => todos().filter(x => x.tipo === "divida").sort((a,b)=> (a.criado||0)-(b.criado||0));
const cfg = () => S.items.get("config") || { id:"config", tipo:"config", orc:0, estrategia:"avalanche" };
const status = d => d.pago ? "paga" : (d.venc && d.venc < HOJE ? "vencida" : "aberta");
function resumo(M){
  const ent = soma(receitas(M)), dm = doMes(M), at = atrasadas(M);
  const desp = soma(dm), pago = soma(dm.filter(d=>d.pago)), atr = soma(at);
  const vencidasMes = soma(dm.filter(d => status(d) === "vencida"));
  return { ent, desp, pago, atr, vencidasMes, sobra: ent - desp - atr, totalDevendo: desp - pago + atr };
}

// ---------- simulação das dívidas (igual ao original) ----------
function simular(lista, orc, estrategia){
  let ds = lista.map(d => ({ id:d.id, nome:d.credor, saldo:+d.saldo||0, j:(+d.juros||0)/100, min:+d.parcela||0 })).filter(d => d.saldo > 0);
  const totais = [ds.reduce((a,d)=>a+d.saldo,0)];
  const quit = {}; let mes = 0, juros = 0, nunca = false;
  const minimo = ds.reduce((a,d)=>a+d.min,0);
  while (ds.some(d => d.saldo > 0.005)) {
    mes++;
    const antes = ds.reduce((a,d)=>a+d.saldo,0);
    for (const d of ds) if (d.saldo > 0) { const jr = d.saldo * d.j; d.saldo += jr; juros += jr; }
    let disp = orc;
    for (const d of ds) if (d.saldo > 0) { const p = Math.min(d.min, d.saldo, disp); d.saldo -= p; disp -= p; }
    const ordem = ds.filter(d => d.saldo > 0).sort(estrategia === "avalanche" ? (a,b) => b.j - a.j || a.saldo - b.saldo : (a,b) => a.saldo - b.saldo || b.j - a.j);
    for (const d of ordem) { if (disp <= 0) break; const p = Math.min(d.saldo, disp); d.saldo -= p; disp -= p; }
    for (const d of ds) if (d.saldo <= 0.005 && !quit[d.id]) { d.saldo = 0; quit[d.id] = mes; }
    const depois = ds.reduce((a,d)=>a+d.saldo,0);
    totais.push(depois);
    if (depois >= antes - 0.01 || mes >= 600) { nunca = true; break; }
  }
  return { meses:mes, juros, quit, totais, nunca, minimo };
}

// ---------- render ----------
function renderNotice(){
  const parts = [];
  if (S.erro) parts.push(`<div class="banner bad">${esc(S.erro)}</div>`);
  if (loadNote) parts.push(`<div class="banner warn">${esc(loadNote)}</div>`);
  if (consolidado()) parts.push(`<div class="banner info">Visão consolidada de todas as empresas. Para lançar, escolha a empresa no formulário ou na mensagem.</div>`);
  if (todos().some(x => x.exemplo) && podeLancar() && !consolidado()) parts.push(`<div class="banner warn"><span>Você está vendo lançamentos de exemplo.</span><button type="button" class="btn sm" data-act="limpa-ex">Apagar exemplos e começar</button></div>`);
  if (localTemDados() && podeLancar()) parts.push(`<div class="banner info"><span>Encontramos lançamentos do WS Finanças antigo salvos neste navegador. Eles não foram apagados.</span><a class="btn sm" href="/app/importar">Importar para o sistema</a></div>`);
  $("#notice").innerHTML = parts.join("");
}
function localTemDados(){ try { const raw = localStorage.getItem(LKEY); return !!raw && JSON.parse(raw).some(x => !x.exemplo && x.tipo !== "config"); } catch(e){ return false; } }

function render(){
  $("#mesLabel").textContent = nomeMes(S.mes);
  document.querySelectorAll("nav.tabs button").forEach(b => b.setAttribute("aria-selected", String(b.dataset.tab === S.tab)));
  renderNotice();
  const v = $("#view");
  if (S.carregando) { v.innerHTML = `<div class="empty">Abrindo suas contas…</div>`; return; }
  const ativo = document.activeElement && document.activeElement.id;
  v.innerHTML = S.tab === "mes" ? viewMes() : S.tab === "dividas" ? viewDividas() : S.tab === "fechamento" ? viewFechamento()
    : S.tab === "fluxo" ? viewFluxo() : S.tab === "relatorios" ? viewRelatorios() : viewWhats();
  if (ativo && document.getElementById(ativo) && ativo !== "q-txt") document.getElementById(ativo).focus();
  if (ativo === "q-txt") $("#q-txt")?.focus();
}

function quickBox(){
  if (!podeLancar()) return "";
  return `<div class="quick">
    <div class="blockhead"><h3>Lançar rápido</h3><span class="muted small">Escreva como mandaria no WhatsApp</span></div>
    <form class="row" data-form="quick"><input id="q-txt" autocomplete="off" maxlength="500" placeholder="Ex.: luz 230 vence 15" aria-label="Mensagem de lançamento"><button class="btn primary" type="submit">Lançar</button></form>
    <div class="chips">${["mercado 380 paguei","internet 120 vence 8","aluguel 1100 dia 5 fixa","paguei luz","recebi salário 3200","quanto tenho para pagar amanhã?"].map(x => `<button type="button" class="chip" data-act="fill" data-t="${esc(x)}">${esc(x)}</button>`).join("")}</div>
    ${S.fb ? `<div class="feedback ${S.fb.ok?"ok":"err"}" style="white-space:pre-line">${esc(S.fb.t)}</div>` : ""}
    ${S.pend ? `<div class="chips"><button type="button" class="btn sm primary" data-act="resp" data-t="sim">Sim</button><button type="button" class="btn sm" data-act="resp" data-t="não">Não</button></div>` : ""}
  </div>`;
}

function itemRow(d, carregada){
  const st = status(d);
  const conf = S.confirm === d.id;
  const meta = [
    `<span>${d.tipo === "receita" ? "recebido " + fmtData(d.data) : (st === "paga" ? "paga em " + fmtData(d.pagoEm || d.venc) : "vence " + fmtData(d.venc))}</span>`,
    d.cat ? `<span>${esc(d.cat)}</span>` : "",
    carregada ? `<span><b>de ${curtoMes(d.mes)}</b></span>` : "",
    d.grupo ? `<span>${d.parcela ? "parcela " + esc(d.parcela) : "fixa"}</span>` : "",
    d.empresa ? `<span>${esc(d.empresa)}</span>` : "",
    d.origem === "whatsapp" ? `<span>via WhatsApp</span>` : "",
    d.exemplo ? `<span class="pill ex">exemplo</span>` : ""
  ].join("");
  const left = d.tipo === "receita"
    ? `<span class="check on" aria-hidden="true">+</span>`
    : `<button type="button" class="check ${d.pago?"on":""}" data-act="toggle" data-id="${d.id}" ${podeLancar()?"":"disabled"} aria-label="${d.pago?"Desmarcar pagamento":"Marcar como paga"}: ${esc(d.desc)}">✓</button>`;
  const pill = d.tipo === "receita" ? "" : `<span class="pill ${st}">${st === "paga" ? "Paga" : st === "vencida" ? "Vencida" : "A vencer"}</span>`;
  return `<div class="item ${d.tipo==="despesa"?st:""}">
    ${left}
    <div style="min-width:0"><div class="desc">${esc(d.desc)}</div><div class="meta">${meta}</div></div>
    <div class="right"><span class="val ${d.tipo==="receita"?"inc":""}">${d.tipo==="receita"?"+ ":""}${BRL(d.valor)}</span>${pill}
      ${podeLancar() ? `<div class="acts"><button type="button" class="icon" data-act="edit" data-id="${d.id}">editar</button><button type="button" class="icon" data-act="del" data-id="${d.id}">excluir</button></div>` : ""}</div>
    ${conf ? `<div class="confirm"><span>Excluir “${esc(d.desc)}”?</span>
      ${d.grupo ? `<button type="button" class="btn sm danger" data-act="del-1" data-id="${d.id}">Só esta</button><button type="button" class="btn sm danger" data-act="del-g" data-id="${d.id}">Esta e as próximas</button>` : `<button type="button" class="btn sm danger" data-act="del-1" data-id="${d.id}">Excluir</button>`}
      <button type="button" class="btn sm" data-act="del-no">Cancelar</button></div>` : ""}
  </div>`;
}

function formPanel(){
  const f = S.form; if (!f) return "";
  const desp = f.tipo === "despesa";
  const emp = f.empresa || (consolidado() ? (BOOT.empresas[0]||{}).id : S.empresa);
  const cats = desp ? CATS : CATS_R;
  const contas = contasDa(emp);
  const reps = [["1","Só este mês"],["f3","Fixa por 3 meses"],["f6","Fixa por 6 meses"],["f12","Fixa por 12 meses"],["p2","Parcelar em 2x"],["p3","Parcelar em 3x"],["p4","Parcelar em 4x"],["p5","Parcelar em 5x"],["p6","Parcelar em 6x"],["p10","Parcelar em 10x"],["p12","Parcelar em 12x"],["p24","Parcelar em 24x"]];
  return `<form class="panel" data-form="lanc">
    <div class="full blockhead"><h3>${f.id ? "Editar lançamento" : "Novo lançamento"}</h3>
      ${f.id ? "" : `<div class="seg" role="group" aria-label="Tipo"><button type="button" data-act="ftipo" data-v="despesa" aria-pressed="${desp}">Despesa</button><button type="button" data-act="ftipo" data-v="receita" aria-pressed="${!desp}">Entrada</button></div>`}</div>
    ${consolidado() && !f.id ? `<div class="field full"><label for="f-emp">Empresa</label><select id="f-emp" data-act="femp">${BOOT.empresas.map(e => `<option value="${e.id}" ${e.id===emp?"selected":""}>${esc(e.nome)}</option>`).join("")}</select></div>` : ""}
    <div class="field full"><label for="f-desc">Descrição</label><input id="f-desc" required maxlength="190" value="${esc(f.desc||"")}" placeholder="${desp?"Ex.: Conta de luz":"Ex.: Salário"}"></div>
    <div class="field"><label for="f-valor">Valor (R$)${!f.id && desp ? " — total, se parcelado" : ""}</label><input id="f-valor" required inputmode="decimal" value="${f.valor!=null?esc(String(f.valor).replace(".",",")):""}" placeholder="0,00"></div>
    <div class="field"><label for="f-data">${desp?"Vencimento":"Data"}</label><input id="f-data" type="date" required value="${esc(f.data)}"></div>
    <div class="field"><label for="f-cat">Categoria</label><select id="f-cat">${cats.map(c=>`<option ${c===f.cat?"selected":""}>${esc(c)}</option>`).join("")}</select></div>
    ${!f.id ? `<div class="field"><label for="f-rep">Repetir / parcelar</label><select id="f-rep">${reps.map(([n,l])=>`<option value="${n}" ${String(f.rep)===n?"selected":""}>${l}</option>`).join("")}</select></div>` : ""}
    ${contas.length ? `<div class="field"><label for="f-conta">Conta bancária</label><select id="f-conta"><option value="">—</option>${contas.map(c=>`<option value="${c.id}" ${c.id===f.conta?"selected":""}>${esc(c.nome)}</option>`).join("")}</select></div>` : ""}
    ${desp ? `<label class="inline-check full"><input type="checkbox" id="f-pago" ${f.pago?"checked":""}> Já está paga</label>` : ""}
    <div class="formacts full"><button type="button" class="btn" data-act="fcancel">Cancelar</button><button type="submit" class="btn primary">${f.id?"Salvar":"Lançar"}</button></div>
  </form>`;
}

function viewMes(){
  const M = S.mes, r = resumo(M), dm = doMes(M), at = atrasadas(M), rc = receitas(M);
  const totalMes = r.desp || 1;
  const fimMes = M < MES_ATUAL || (M === MES_ATUAL && DIA_HOJE >= 25);
  const vazio = !todos().some(x => x.tipo !== "config");
  return `<div style="display:flex;flex-direction:column;gap:18px">
    <div class="stats">
      <div class="stat good"><label>Entradas</label><b>${BRL(r.ent)}</b></div>
      <div class="stat"><label>Contas do mês</label><b>${BRL(r.desp)}</b></div>
      <div class="stat ${r.atr>0?"bad":""}"><label>Atrasadas de antes</label><b>${BRL(r.atr)}</b></div>
      <div class="stat ${r.sobra<0?"bad":"good"}"><label>Sobra prevista</label><b>${BRL(r.sobra)}</b></div>
    </div>
    ${r.desp > 0 ? `<div class="progress"><div class="bar" role="img" aria-label="Pago ${BRL(r.pago)} de ${BRL(r.desp)}"><i class="p" style="width:${(r.pago/totalMes*100).toFixed(1)}%"></i><i class="l" style="width:${(r.vencidasMes/totalMes*100).toFixed(1)}%"></i></div>
      <div class="small muted num">Pago ${BRL(r.pago)} de ${BRL(r.desp)}${r.vencidasMes>0?` · <span style="color:var(--danger);font-weight:700">${BRL(r.vencidasMes)} vencidas neste mês</span>`:""}</div></div>` : ""}
    ${fimMes && dm.length ? `<div class="banner good"><span>O fechamento de ${nomeMes(M)} está pronto, com sugestões de economia.</span><button type="button" class="btn sm" data-act="go" data-tab="fechamento">Ver fechamento</button></div>` : ""}
    ${quickBox()}
    ${!podeLancar() ? "" : S.form ? formPanel() : `<div><button type="button" class="btn" data-act="new">+ Novo lançamento com detalhes</button></div>`}
    ${vazio ? `<div class="list"><div class="empty"><p style="margin:0 0 10px">Nenhuma conta lançada ainda. Comece pelas contas fixas (aluguel, luz, água, internet) e pelas entradas.</p>${podeLancar() && !consolidado() ? `<button type="button" class="btn" data-act="exemplo">Ver com dados de exemplo</button>` : ""}</div></div>` : ""}
    ${at.length ? `<section class="block"><div class="blockhead"><h2 style="color:var(--danger)">Vencidas de meses anteriores</h2><span class="num" style="color:var(--danger);font-weight:700">${BRL(r.atr)}</span></div>
      <div class="list late">${at.map(d => itemRow(d, true)).join("")}</div>
      <p class="small muted" style="margin:0">Elas continuam aparecendo aqui todo mês até você marcar como pagas.</p></section>` : ""}
    ${!vazio ? `<section class="block"><div class="blockhead"><h2>Contas de ${nomeMes(M)}</h2><span class="muted num">${dm.filter(d=>d.pago).length} de ${dm.length} pagas</span></div>
      <div class="list">${dm.length ? dm.map(d => itemRow(d, false)).join("") : `<div class="empty">Nenhuma conta neste mês.</div>`}</div></section>
    <section class="block"><div class="blockhead"><h2>Entradas</h2><span class="num inc" style="font-weight:700">${BRL(r.ent)}</span></div>
      <div class="list">${rc.length ? rc.map(d => itemRow(d, false)).join("") : `<div class="empty">Nenhuma entrada lançada. Escreva “recebi salário 3200” acima.</div>`}</div></section>` : ""}
  </div>`;
}

function chartSaldo(totais){
  const W = 640, H = 200, L = 56, R = 14, T = 14, B = 28;
  const max = Math.max(...totais, 1);
  const step = Math.pow(10, Math.floor(Math.log10(max))) ; let tick = step; if (max/tick > 5) tick *= 2; if (max/tick > 5) tick *= 2.5;
  const top = Math.ceil(max / tick) * tick;
  const n = totais.length - 1 || 1;
  const x = i => L + (W - L - R) * i / n, y = v => T + (H - T - B) * (1 - v / top);
  const pts = totais.map((v,i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(" ");
  const ticks = []; for (let v = 0; v <= top + 0.1; v += tick) ticks.push(v);
  const xl = [0, Math.round(n/2), n].filter((v,i,a)=>a.indexOf(v)===i);
  return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Saldo total das dívidas mês a mês">
    ${ticks.map(v => `<line x1="${L}" x2="${W-R}" y1="${y(v)}" y2="${y(v)}" stroke="var(--line)" stroke-width="1"/><text x="${L-8}" y="${y(v)+4}" text-anchor="end" font-size="11" fill="var(--muted)">${v>=1000?(v/1000).toLocaleString("pt-BR")+" mil":v}</text>`).join("")}
    <polygon points="${x(0)},${y(0)} ${pts} ${x(n)},${y(0)}" fill="var(--accent-soft)"/>
    <polyline points="${pts}" fill="none" stroke="var(--accent)" stroke-width="2.5" stroke-linejoin="round"/>
    <circle cx="${x(0)}" cy="${y(totais[0])}" r="4" fill="var(--accent)"/>
    <circle cx="${x(n)}" cy="${y(totais[n])}" r="4" fill="var(--accent)"/>
    ${totais.map((v,i) => `<rect x="${(x(i)-(W-L-R)/n/2).toFixed(1)}" y="${T}" width="${((W-L-R)/n).toFixed(1)}" height="${H-T-B}" fill="transparent"><title>${curtoMes(addMes(MES_ATUAL, i))}: ${BRL(v)}</title></rect>`).join("")}
    ${xl.map(i => `<text x="${x(i)}" y="${H-8}" text-anchor="${i===0?"start":i===n?"end":"middle"}" font-size="11" fill="var(--muted)">${curtoMes(addMes(MES_ATUAL, i))}</text>`).join("")}
  </svg>`;
}

function viewDividas(){
  const ds = dividas(), c = cfg();
  const f = S.divForm;
  const pode = podeLancar();
  const form = !pode ? "" : f ? `<form class="panel" data-form="div">
    <div class="full"><h3>${f.id?"Atualizar dívida":"Nova dívida"}</h3></div>
    ${consolidado() && !f.id ? `<div class="field full"><label for="d-emp">Empresa</label><select id="d-emp">${BOOT.empresas.map(e => `<option value="${e.id}">${esc(e.nome)}</option>`).join("")}</select></div>` : ""}
    <div class="field full"><label for="d-credor">Credor</label><input id="d-credor" required maxlength="150" value="${esc(f.credor||"")}" placeholder="Ex.: Cartão de crédito, empréstimo, cheque especial"></div>
    <div class="field"><label for="d-saldo">Quanto deve hoje (R$)</label><input id="d-saldo" required inputmode="decimal" value="${f.saldo!=null?String(f.saldo).replace(".",","):""}" placeholder="0,00"></div>
    <div class="field"><label for="d-juros">Juros ao mês (%)</label><input id="d-juros" required inputmode="decimal" value="${f.juros!=null?String(f.juros).replace(".",","):""}" placeholder="Ex.: 12"></div>
    <div class="field"><label for="d-parc">Parcela mínima (R$)</label><input id="d-parc" required inputmode="decimal" value="${f.parcela!=null?String(f.parcela).replace(".",","):""}" placeholder="0,00"></div>
    <div class="formacts full"><button type="button" class="btn" data-act="dcancel">Cancelar</button><button type="submit" class="btn primary">${f.id?"Salvar":"Adicionar"}</button></div>
  </form>` : `<div><button type="button" class="btn" data-act="dnew">+ Adicionar dívida</button></div>`;
  const lista = ds.length ? `<div class="list">${ds.map(d => `<div class="item"><span class="step">${esc((d.credor||"?").charAt(0).toUpperCase())}</span>
      <div style="min-width:0"><div class="desc">${esc(d.credor)}</div><div class="meta"><span>juros ${String(d.juros).replace(".",",")}% a.m.</span><span>parcela mín. ${BRL(d.parcela)}</span>${d.empresa?`<span>${esc(d.empresa)}</span>`:""}${d.exemplo?`<span class="pill ex">exemplo</span>`:""}</div></div>
      <div class="right"><span class="val">${BRL(d.saldo)}</span>${pode ? `<div class="acts"><button type="button" class="icon" data-act="dedit" data-id="${d.id}">atualizar</button><button type="button" class="icon" data-act="ddel" data-id="${d.id}">quitada/excluir</button></div>` : ""}</div>
      ${S.confirm===d.id?`<div class="confirm"><span>Remover “${esc(d.credor)}” do plano?</span><button type="button" class="btn sm danger" data-act="ddel-ok" data-id="${d.id}">Remover</button><button type="button" class="btn sm" data-act="del-no">Cancelar</button></div>`:""}
    </div>`).join("")}</div>` : `<div class="list"><div class="empty">Cadastre cada dívida com o saldo de hoje, os juros do mês e a parcela mínima. O plano aparece logo abaixo.</div></div>`;

  let plano = "";
  if (ds.length) {
    const orc = +c.orc || 0;
    const sim = simular(ds, orc, c.estrategia);
    const outra = simular(ds, orc, c.estrategia === "avalanche" ? "bola" : "avalanche");
    const sobra = S.mes === MES_ATUAL ? resumo(MES_ATUAL).sobra : 0;
    const sobraMin = sim.minimo;
    let head;
    if (orc <= 0) head = `<div class="headline"><span class="muted">Informe quanto você consegue separar por mês para as dívidas.</span></div>`;
    else if (orc < sobraMin - 0.01) head = `<div class="headline bad"><span class="big num">Falta ${BRL(sobraMin - orc)}</span><span>O valor separado não cobre a soma das parcelas mínimas (${BRL(sobraMin)}). Renegociar a dívida com maior juros costuma ser o primeiro passo.</span></div>`;
    else if (sim.nunca) head = `<div class="headline bad"><span class="big">Os juros crescem mais rápido que os pagamentos</span><span>Com ${BRL(orc)} por mês a dívida não diminui. Aumente o valor mensal ou renegocie para reduzir os juros.</span></div>`;
    else head = `<div class="headline good"><span class="muted small">Livre das dívidas em</span><span class="big num">${sim.meses} ${sim.meses===1?"mês":"meses"} · ${curtoMes(addMes(MES_ATUAL, sim.meses))}</span>
      <span class="muted num">Total de juros no caminho: ${BRL(sim.juros)}${!outra.nunca && Math.abs(outra.juros - sim.juros) > 1 ? ` · pela outra estratégia seriam ${outra.meses} meses e ${BRL(outra.juros)} de juros` : ""}</span></div>`;
    const ordem = [...ds].map(d => ({ d, m: sim.quit[d.id] })).sort((a,b) => (a.m||9999) - (b.m||9999));
    const editavel = pode && !consolidado();
    plano = `<section class="block"><h2>Seu plano</h2>
      <form class="panel" data-form="cfg" style="border-color:var(--line)">
        <div class="field"><label for="c-orc">Quanto posso pagar por mês nas dívidas (R$)</label><input id="c-orc" inputmode="decimal" ${editavel?"":"disabled"} value="${orc?String(orc).replace(".",","):""}" placeholder="0,00"></div>
        <div class="field"><label>Estratégia</label><div class="seg" role="group"><button type="button" ${editavel?"":"disabled"} data-act="estr" data-v="avalanche" aria-pressed="${c.estrategia!=="bola"}">Maior juros primeiro</button><button type="button" ${editavel?"":"disabled"} data-act="estr" data-v="bola" aria-pressed="${c.estrategia==="bola"}">Menor saldo primeiro</button></div></div>
        <p class="full small muted" style="margin:0">Paga-se sempre a parcela mínima de todas; o que sobrar vai inteiro para a dívida da vez. Quando uma acaba, a parcela dela passa para a próxima. ${sobra>0?`A sobra prevista deste mês é ${BRL(sobra)}.`:""}${consolidado()?" Na visão consolidada o valor é a soma do que cada empresa separa.":""}</p>
        ${editavel ? `<div class="formacts full">${sobra>0?`<button type="button" class="btn" data-act="usa-sobra" data-v="${(sobra+0).toFixed(2)}">Usar mínimas + sobra (${BRL(sobraMin + sobra)})</button>`:""}<button type="submit" class="btn primary">Calcular</button></div>` : ""}
      </form>
      ${head}
      ${orc >= sobraMin - 0.01 && !sim.nunca && orc > 0 ? `<div class="grid2">
        <div class="list"><ol class="order">${ordem.map((o,i) => `<li><span class="step">${i+1}</span><div style="min-width:0"><div class="desc">${esc(o.d.credor)}</div><div class="meta">${BRL(o.d.saldo)} · ${String(o.d.juros).replace(".",",")}% a.m.</div></div><span class="num small" style="font-weight:700">quitada em ${o.m ? curtoMes(addMes(MES_ATUAL, o.m)) : "—"}</span></li>`).join("")}</ol></div>
        <div class="chart">${chartSaldo(sim.totais)}<div class="small muted" style="padding:4px 6px 0">Quanto você ainda deve, mês a mês</div></div>
      </div>` : ""}
    </section>`;
  }
  return `<div style="display:flex;flex-direction:column;gap:18px">
    <section class="block"><div class="blockhead"><h2>Dívidas</h2><span class="num" style="font-weight:700">${BRL(ds.reduce((a,d)=>a+(+d.saldo||0),0))} no total</span></div>${lista}${form}</section>
    ${plano}
  </div>`;
}

function analisar(M){
  const r = resumo(M), dm = doMes(M);
  const base = r.ent > 0 ? r.ent : r.desp;
  const porCat = {};
  dm.forEach(d => { porCat[d.cat||"Outros"] = (porCat[d.cat||"Outros"]||0) + (+d.valor||0); });
  const sugs = [];
  if (r.atr > 0) sugs.push({ bad:true, t:"Priorize as contas vencidas", p:`Há ${BRL(r.atr)} em contas atrasadas de meses anteriores. Boletos costumam cobrar 2% de multa mais cerca de 1% de juros ao mês; ligue para o credor e peça acordo sem juros antes de assumir conta nova.`, v:0 });
  if (r.sobra < 0) sugs.push({ bad:true, t:"O mês fecha no vermelho", p:`Faltam ${BRL(-r.sobra)} para cobrir todas as contas. Corte primeiro as categorias marcadas abaixo e evite usar o cheque especial para fechar a diferença.`, v:0 });
  for (const cat of Object.keys(porCat)) {
    if (cat === "Dívidas") continue;
    const lim = (REF[cat]||5) / 100 * base, g = porCat[cat];
    if (g > lim + 1) sugs.push({ t:`Reduzir ${cat.toLowerCase()}`, p:`Você gastou ${BRL(g)} (${(g/base*100).toFixed(0)}% ${r.ent>0?"da renda":"dos gastos"}). O recomendado é até ${REF[cat]||5}%. ${DICAS[cat]||""}`, v: g - lim });
    else if (cat === "Assinaturas" && g > 0) { const menor = Math.min(...dm.filter(d=>d.cat==="Assinaturas").map(d=>+d.valor)); sugs.push({ t:"Revisar assinaturas", p:DICAS["Assinaturas"], v:menor }); }
    else if (cat === "Delivery e lanches" && g > 0) sugs.push({ t:"Cortar metade do delivery", p:DICAS["Delivery e lanches"], v:g/2 });
  }
  sugs.sort((a,b) => (b.bad?1:0)-(a.bad?1:0) || b.v - a.v);
  const economia = sugs.reduce((a,s)=>a+s.v,0);
  return { r, porCat, base, sugs, economia };
}

function viewFechamento(){
  const M = S.mes, a = analisar(M), dm = doMes(M);
  if (!dm.length) return `<div class="list"><div class="empty">Lance as contas de ${nomeMes(M)} para ver o fechamento e as sugestões de economia.</div></div>`;
  const parcial = M >= MES_ATUAL;
  const cats = Object.entries(a.porCat).sort((x,y)=>y[1]-x[1]);
  const escala = Math.max(...cats.map(([c,g]) => Math.max(g, c==="Dívidas"?0:(REF[c]||5)/100*a.base)), 1);
  const ds = dividas(), c = cfg();
  let impacto = "";
  if (ds.length && +c.orc > 0 && a.economia > 0) {
    const s1 = simular(ds, +c.orc, c.estrategia), s2 = simular(ds, +c.orc + a.economia, c.estrategia);
    if (!s2.nunca && (s1.nunca || s2.meses < s1.meses)) impacto = `<span>Colocando essa economia nas dívidas, o prazo cai de <b>${s1.nunca?"sem previsão":s1.meses+" meses"}</b> para <b>${s2.meses} meses</b>${s1.nunca?"":` e você deixa de pagar ${BRL(Math.max(0,s1.juros - s2.juros))} de juros`}.</span>`;
  }
  return `<div style="display:flex;flex-direction:column;gap:18px">
    <div class="headline ${a.r.sobra<0?"bad":"good"}"><span class="muted small">Fechamento de ${nomeMes(M)}${parcial?" (mês em andamento, números parciais)":""}</span>
      <span class="big num">${a.economia > 0 ? `Dá para liberar ${BRL(a.economia)} por mês` : a.r.sobra >= 0 ? "Gastos dentro do recomendado" : `Faltam ${BRL(-a.r.sobra)}`}</span>
      <span class="muted num">Entradas ${BRL(a.r.ent)} · contas ${BRL(a.r.desp)} · atrasadas ${BRL(a.r.atr)} · sobra ${BRL(a.r.sobra)}</span>${impacto}</div>
    <section class="block"><div class="blockhead"><h2>Onde o dinheiro foi</h2><span class="small muted">A linha marca o limite recomendado ${a.r.ent>0?"pela renda":"(sem entradas lançadas, uso o total gasto)"}</span></div>
      <div class="cats">${cats.map(([cat,g]) => { const lim = cat==="Dívidas" ? null : (REF[cat]||5)/100*a.base; const over = lim != null && g > lim + 1;
        return `<div class="cat"><div class="top"><span><b>${esc(cat)}</b>${lim!=null?` <span class="muted small">limite ${BRL(lim)}</span>`:""}</span><span class="num" style="font-weight:700;${over?"color:var(--danger)":""}">${BRL(g)}</span></div>
        <div class="track"><div class="fill ${over?"over":""}" style="width:${(g/escala*100).toFixed(1)}%"></div>${lim!=null?`<div class="lim" style="left:${Math.min(100,lim/escala*100).toFixed(1)}%"></div>`:""}</div></div>`; }).join("")}</div></section>
    <section class="block"><h2>Sugestões para economizar</h2>
      <div class="sugs">${a.sugs.length ? a.sugs.map(s => `<div class="sug ${s.bad?"bad":""}"><h3 style="${s.bad?"color:var(--danger)":""}">${esc(s.t)}</h3><span class="save">${s.v>0?"+ "+BRL(s.v):""}</span><p class="full" style="grid-column:1/-1">${esc(s.p)}</p></div>`).join("") : `<div class="sug"><h3>Tudo dentro do recomendado</h3><span></span><p style="grid-column:1/-1">Mantenha o ritmo e direcione a sobra para a dívida com maior juros.</p></div>`}</div></section>
    ${BOOT.ia ? `<section class="ai"><div class="blockhead"><h2>Análise do Claude</h2>${S.ai.state==="run"?`<span class="muted small">Analisando…</span>`:`<button type="button" class="btn sm primary" data-act="ai">${S.ai.text?"Pedir de novo":"Pedir análise personalizada"}</button>`}</div>
      <div class="out">${S.ai.state==="run" && !S.ai.text ? `<p class="muted">Pensando…</p>` : S.ai.text ? S.ai.text.split(/\n+/).filter(Boolean).map(p=>`<p>${esc(p.replace(/\*\*/g,""))}</p>`).join("") : `<p class="muted small">O Claude lê os números deste mês e das dívidas e monta um plano em linguagem simples.</p>`}</div>
      ${S.ai.err ? `<p class="small" style="color:var(--danger);margin:0">${esc(S.ai.err)}</p>` : ""}</section>` : ""}
  </div>`;
}

// ---------- fluxo de caixa ----------
function chartFluxo(series){
  const W = 640, H = 220, L = 56, R = 14, T = 14, B = 30;
  const max = Math.max(1, ...series.map(s => Math.max(s.entradas, s.saidas)));
  const step = Math.pow(10, Math.floor(Math.log10(max))); let tick = step; if (max/tick > 5) tick *= 2; if (max/tick > 5) tick *= 2.5;
  const top = Math.ceil(max / tick) * tick;
  const n = series.length, band = (W - L - R) / n, bw = Math.max(4, Math.min(16, band/2 - 4));
  const y = v => T + (H - T - B) * (1 - v / top);
  const ticks = []; for (let v = 0; v <= top + 0.1; v += tick) ticks.push(v);
  const bar = (x, v, fill) => { const h = Math.max(0, y(0) - y(v)); if (h <= 0) return ""; const r = Math.min(4, h, bw/2);
    return `<path d="M${x},${y(0)} v${-(h-r)} q0,${-r} ${r},${-r} h${bw-2*r} q${r},0 ${r},${r} v${h-r} z" fill="${fill}"/>`; };
  return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Entradas e saídas por mês">
    ${ticks.map(v => `<line x1="${L}" x2="${W-R}" y1="${y(v)}" y2="${y(v)}" stroke="var(--line)" stroke-width="1"/><text x="${L-8}" y="${y(v)+4}" text-anchor="end" font-size="11" fill="var(--muted)">${v>=1000?(v/1000).toLocaleString("pt-BR")+" mil":v}</text>`).join("")}
    ${series.map((s,i) => { const x0 = L + band*i + band/2; return `<g>
      ${bar(x0 - bw - 1, s.entradas, "var(--accent)")}${bar(x0 + 1, s.saidas, "var(--muted)")}
      <rect x="${(L+band*i).toFixed(1)}" y="${T}" width="${band.toFixed(1)}" height="${H-T-B}" fill="transparent"><title>${curtoMes(s.mes)} — entradas ${BRL(s.entradas)} · saídas ${BRL(s.saidas)} · saldo ${BRL(s.saldo)}</title></rect>
      <text x="${x0}" y="${H-10}" text-anchor="middle" font-size="10.5" fill="${s.mes===S.mes?"var(--ink)":"var(--muted)"}" ${s.mes===S.mes?'font-weight="700"':""}>${MESES_C[+s.mes.slice(5,7)-1]}</text></g>`; }).join("")}
  </svg>`;
}
function viewFluxo(){
  if (!S.fluxo) { carregarFluxo(); return `<div class="empty">Calculando o fluxo de caixa…</div>`; }
  const f = S.fluxo, cur = f.series.find(s => s.mes === S.mes) || {entradas:0,saidas:0,saldo:0};
  const saldoContas = f.bancos.reduce((a,b)=>a+b.saldo,0);
  const dias = f.diario.filter(d => d.entra || d.sai);
  let acc = 0;
  return `<div style="display:flex;flex-direction:column;gap:18px">
    <div class="stats">
      <div class="stat"><label>Saldo nas contas</label><b>${f.bancos.length ? BRL(saldoContas) : "—"}</b></div>
      <div class="stat good"><label>Entradas do mês</label><b>${BRL(cur.entradas)}</b></div>
      <div class="stat"><label>Saídas do mês</label><b>${BRL(cur.saidas)}</b></div>
      <div class="stat ${cur.saldo<0?"bad":"good"}"><label>Resultado do mês</label><b>${BRL(cur.saldo)}</b></div>
    </div>
    <section class="block"><div class="blockhead"><h2>Entradas e saídas</h2><span class="small muted">6 meses antes e 6 depois de ${nomeMes(S.mes)}</span></div>
      <div class="chart">${chartFluxo(f.series)}<div class="legend"><span><i style="background:var(--accent)"></i>Entradas</span><span><i style="background:var(--muted)"></i>Saídas (contas do mês)</span></div></div>
      <div class="table-wrap"><table class="t"><thead><tr><th>Mês</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Pago</th><th class="n">Resultado</th></tr></thead><tbody>
      ${f.series.map(s => `<tr><td>${curtoMes(s.mes)}</td><td class="n">${BRL(s.entradas)}</td><td class="n">${BRL(s.saidas)}</td><td class="n">${BRL(s.pago)}</td><td class="n" style="${s.saldo<0?"color:var(--danger);font-weight:700":""}">${BRL(s.saldo)}</td></tr>`).join("")}
      </tbody></table></div></section>
    <section class="block"><div class="blockhead"><h2>Dia a dia de ${nomeMes(S.mes)}</h2><span class="small muted">O que entra e o que vence em cada dia</span></div>
      <div class="list">${dias.length ? dias.map(d => { acc += d.entra - d.sai; return `<div class="item"><span class="step">${d.dia.slice(8,10)}</span>
        <div style="min-width:0"><div class="desc">${d.entra?`<span class="inc">+ ${BRL(d.entra)}</span> `:""}${d.sai?`<span>− ${BRL(d.sai)}</span>`:""}</div><div class="meta"><span>${d.dia < HOJE ? "passado" : d.dia === HOJE ? "hoje" : "previsto"}</span></div></div>
        <div class="right"><span class="val" style="${acc<0?"color:var(--danger)":""}">${BRL(acc)}</span><span class="small muted">acumulado</span></div></div>`; }).join("") : `<div class="empty">Sem movimentação lançada neste mês.</div>`}</div></section>
    <section class="block"><div class="blockhead"><h2>Contas bancárias</h2>${BOOT.podeLancar?`<a class="small" href="/app/empresas">gerenciar</a>`:""}</div>
      <div class="list">${f.bancos.length ? f.bancos.map(b => `<div class="item"><span class="step">${esc(b.nome.charAt(0).toUpperCase())}</span><div style="min-width:0"><div class="desc">${esc(b.nome)}</div><div class="meta"><span>${esc(b.banco||"")}</span><span>${esc(b.empresa)}</span></div></div><div class="right"><span class="val" style="${b.saldo<0?"color:var(--danger)":""}">${BRL(b.saldo)}</span></div></div>`).join("")
        : `<div class="empty">Cadastre as contas bancárias em Empresas e contas e escolha a conta ao lançar para acompanhar o saldo.</div>`}</div></section>
  </div>`;
}
async function carregarFluxo(){
  try { S.fluxo = await api("GET", `/app/api/fluxo?${qs({ empresa:S.empresa, mes:S.mes })}`); }
  catch(e) { S.fluxo = { series:[], bancos:[], diario:[] }; aviso(e.message); }
  if (S.tab === "fluxo") render();
}

// ---------- relatórios ----------
function viewRelatorios(){
  if (!S.rel) { carregarRel(); return `<div class="empty">Montando o relatório…</div>`; }
  const r = S.rel;
  const maxCat = Math.max(1, ...r.por_categoria.map(c => c.total));
  const link = f => `/app/relatorios/exportar?${qs({ empresa:S.empresa, de:S.relDe, ate:S.relAte, formato:f })}`;
  return `<div style="display:flex;flex-direction:column;gap:18px">
    <form class="panel" data-form="rel" style="border-color:var(--line)">
      <div class="field"><label for="r-de">De</label><input id="r-de" type="month" value="${S.relDe}" required></div>
      <div class="field"><label for="r-ate">Até</label><input id="r-ate" type="month" value="${S.relAte}" required></div>
      <div class="formacts full">${BOOT.podeExportar ? `<a class="btn" href="${link("csv")}">Exportar CSV (Excel)</a>${BOOT.relatoriosAvancados ? `<a class="btn" href="${link("pdf")}">Exportar PDF</a>` : ""}` : ""}<button type="submit" class="btn primary">Atualizar</button></div>
    </form>
    <div class="stats">
      <div class="stat good"><label>Entradas</label><b>${BRL(r.entradas)}</b></div>
      <div class="stat"><label>Saídas</label><b>${BRL(r.saidas)}</b></div>
      <div class="stat ${r.resultado<0?"bad":"good"}"><label>Resultado</label><b>${BRL(r.resultado)}</b></div>
      <div class="stat ${r.situacao.atrasadas>0?"bad":""}"><label>Atrasadas</label><b>${BRL(r.situacao.atrasadas)}</b></div>
    </div>
    <div class="grid2">
      <div class="card"><h3>Contas por situação</h3>
        <div class="list" style="border:0">${[["Pagas",r.situacao.pagas,"paga"],["Pendentes (a vencer)",r.situacao.pendentes,"aberta"],["Atrasadas",r.situacao.atrasadas,"vencida"]].map(([l,v,c]) => `<div class="item" style="grid-template-columns:minmax(0,1fr) auto"><span class="pill ${c}" style="justify-self:start">${l}</span><span class="val">${BRL(v)}</span></div>`).join("")}</div></div>
      <div class="card"><h3>Entradas por categoria</h3>
        <div class="list" style="border:0">${r.receitas_por_categoria.length ? r.receitas_por_categoria.map(c => `<div class="item" style="grid-template-columns:minmax(0,1fr) auto"><span>${esc(c.categoria)}</span><span class="val inc">${BRL(c.total)}</span></div>`).join("") : `<div class="empty">Sem entradas no período.</div>`}</div></div>
    </div>
    <section class="block"><h2>Despesas por categoria</h2>
      <div class="cats">${r.por_categoria.length ? r.por_categoria.map(c => `<div class="cat"><div class="top"><span><b>${esc(c.categoria)}</b> <span class="muted small">${c.qtd} lançamento(s)</span></span><span class="num" style="font-weight:700">${BRL(c.total)}</span></div>
        <div class="track"><div class="fill" style="width:${(c.total/maxCat*100).toFixed(1)}%"></div></div></div>`).join("") : `<div class="empty">Sem despesas no período.</div>`}</div></section>
    ${r.por_empresa.length > 1 ? `<section class="block"><h2>Por empresa</h2><div class="table-wrap"><table class="t"><thead><tr><th>Empresa</th><th class="n">Entradas</th><th class="n">Saídas</th><th class="n">Resultado</th></tr></thead><tbody>
      ${r.por_empresa.map(e => `<tr><td>${esc(e.empresa)}</td><td class="n">${BRL(e.entradas)}</td><td class="n">${BRL(e.saidas)}</td><td class="n" style="${e.resultado<0?"color:var(--danger);font-weight:700":""}">${BRL(e.resultado)}</td></tr>`).join("")}
      </tbody></table></div></section>` : ""}
  </div>`;
}
async function carregarRel(){
  try { S.rel = await api("GET", `/app/api/relatorio?${qs({ empresa:S.empresa, de:S.relDe, ate:S.relAte })}`); }
  catch(e) { S.rel = { entradas:0, saidas:0, resultado:0, por_categoria:[], receitas_por_categoria:[], por_empresa:[], situacao:{pagas:0,pendentes:0,atrasadas:0} }; aviso(e.message); }
  if (S.tab === "relatorios") render();
}

function viewWhats(){
  const ex = [
    ["paguei 350 de energia","Lança a energia como paga (ou marca a conta em aberto)"],
    ["recebi 5.000 de um cliente","Lança uma entrada"],
    ["conta de 1.200 para pagar dia 20","Cadastra uma conta a pagar"],
    ["aluguel 1.100 dia 5 fixa","Lança o aluguel e repete nos próximos 12 meses"],
    ["geladeira 3.000 em 10x","Parcela em 10 vezes"],
    ["quanto tenho para pagar amanhã?","Consulta as contas do dia seguinte"],
    ["paguei internet","Marca a internet em aberto como paga"],
    ["desfazer","Cancela o último lançamento"]
  ];
  const num = BOOT.whatsappNumero;
  return `<div style="display:flex;flex-direction:column;gap:18px">
    ${quickBox()}
    <section class="block"><h2>O que você pode escrever</h2>
      <div class="examples">${ex.map(([c,d]) => `<button type="button" class="ex" data-act="fill" data-t="${esc(c)}" style="text-align:left"><code>${esc(c)}</code><span class="small muted">${esc(d)}</span></button>`).join("")}</div></section>
    <section class="block prose"><h2>Pelo WhatsApp</h2>
      ${BOOT.whatsapp ? `<p>Mande as mesmas frases para o WhatsApp oficial do WS Finanças${num ? ` (<b>${esc(num)}</b>)` : ""} a partir de um número autorizado. O lançamento entra aqui na hora e você recebe a confirmação. Quando houver dúvida (qual empresa, descrição, possível repetição), o assistente pergunta antes de lançar. Responda <b>DESFAZER</b> para cancelar o último lançamento.</p>
        <p class="muted">Para escrever em nome de uma empresa específica, diga o nome ou apelido: “paguei 350 de energia da empresa A”. ${BOOT.podeLancar ? `<a href="/app/whatsapp">Gerenciar números autorizados</a>.` : ""}</p>`
        : `<p>O assistente no WhatsApp está disponível nos planos Profissional e Empresarial. Enquanto isso, esta caixa funciona do mesmo jeito: salve esta página na tela inicial do celular e lance as contas como se estivesse mandando mensagem.</p>`}
    </section>
  </div>`;
}

// ---------- ações ----------
async function lancarTexto(txt){
  if (!txt.trim()) return;
  try {
    const r = await api("POST", "/app/api/mensagem", { texto:txt, empresa: consolidado() ? null : S.empresa, mes:S.mes });
    S.fb = { ok:r.ok, t:r.texto }; S.pend = !!r.pendente;
  } catch(e) { S.fb = { ok:false, t:e.message }; S.pend = false; }
  S.rel = null; await carregar();
}
async function pedirAnalise(){
  const M = S.mes, a = analisar(M), c = cfg(), ds = dividas();
  const sim = ds.length && +c.orc > 0 ? simular(ds, +c.orc, c.estrategia) : null;
  const linhas = [
    `Mês: ${nomeMes(M)}. Entradas: ${BRL(a.r.ent)}. Contas do mês: ${BRL(a.r.desp)} (pago ${BRL(a.r.pago)}). Atrasadas de meses anteriores: ${BRL(a.r.atr)}. Sobra prevista: ${BRL(a.r.sobra)}.`,
    `Gastos por categoria: ${Object.entries(a.porCat).map(([k,v])=>`${k} ${BRL(v)}`).join("; ")}.`,
    `Contas: ${doMes(M).slice(0,60).map(d=>`${d.desc} ${BRL(d.valor)} (${status(d)})`).join("; ")}.`,
    ds.length ? `Dívidas: ${ds.map(d=>`${d.credor}: saldo ${BRL(d.saldo)}, juros ${d.juros}% a.m., parcela mínima ${BRL(d.parcela)}`).join("; ")}. Valor mensal para dívidas: ${BRL(c.orc)}. ${sim ? (sim.nunca ? "Com esse valor a dívida não diminui." : `Previsão de quitação: ${sim.meses} meses.`) : ""}` : "Nenhuma dívida cadastrada."
  ];
  S.ai = { state:"run", text:"", err:"" }; render();
  try { const r = await api("POST", "/app/api/ia/analise", { resumo: linhas.join("\n").slice(0, 5900) }); S.ai = { state:"idle", text:r.texto, err:"" }; }
  catch(e) { S.ai = { state:"idle", text:"", err:e.message }; }
  render();
}
function lerForm(){
  const g = id => document.getElementById(id);
  return { desc:g("f-desc")?.value, valor: g("f-valor")?.value ? parseValor(g("f-valor").value) : undefined, data:g("f-data")?.value, cat:g("f-cat")?.value || S.form?.cat,
    rep:g("f-rep")?.value || "1", pago:g("f-pago")?.checked, conta:g("f-conta")?.value || null, empresa:g("f-emp")?.value || S.form?.empresa };
}
const recarregarTudo = () => { S.fluxo = null; S.rel = null; S.ai = {state:"idle",text:""}; S.fb = null; S.pend = false; S.carregando = true; render(); carregar(); };

document.addEventListener("click", async ev => {
  const b = ev.target.closest("[data-act],[data-tab],#prev,#next");
  if (!b || b.disabled) return;
  if (b.id === "prev") { S.mes = addMes(S.mes, -1); return recarregarTudo(); }
  if (b.id === "next") { S.mes = addMes(S.mes, 1); return recarregarTudo(); }
  if (b.dataset.tab && !b.dataset.act) { S.tab = b.dataset.tab; S.confirm = null; return render(); }
  const act = b.dataset.act, id = b.dataset.id, it = id ? S.items.get(id) : null;
  switch (act) {
    case "go": S.tab = b.dataset.tab; render(); window.scrollTo(0,0); break;
    case "fill": { if (S.tab !== "mes" && S.tab !== "whats") S.tab = "mes"; render(); const q = $("#q-txt"); if (q) { q.value = b.dataset.t; q.focus(); } break; }
    case "resp": S.pend = false; await lancarTexto(b.dataset.t); break;
    case "toggle": if (it) await acao(() => api("POST", `/app/api/lancamentos/${it.id}/pago`, { pago:!it.pago })); break;
    case "new": S.form = { tipo:"despesa", data: S.mes === MES_ATUAL ? HOJE : `${S.mes}-01`, cat:"Outros", rep:"1" }; render(); $("#f-desc")?.focus(); break;
    case "edit": if (it) { S.form = { id, tipo:it.tipo, desc:it.desc, valor:it.valor, data: it.tipo==="receita" ? it.data : it.venc, cat:it.cat, pago:it.pago, conta:it.conta, empresa:it.company_id }; S.tab = "mes"; render(); $("#f-desc")?.focus(); } break;
    case "ftipo": { const f = lerForm(); S.form = { ...S.form, ...f, tipo:b.dataset.v, cat: b.dataset.v === "receita" ? CATS_R[0] : "Outros" }; render(); break; }
    case "fcancel": S.form = null; render(); break;
    case "del": case "ddel": S.confirm = id; render(); break;
    case "del-no": S.confirm = null; render(); break;
    case "del-1": S.confirm = null; await acao(() => api("DELETE", `/app/api/lancamentos/${id}`)); break;
    case "del-g": S.confirm = null; await acao(() => api("DELETE", `/app/api/lancamentos/${id}?escopo=grupo`)); break;
    case "ddel-ok": S.confirm = null; await acao(() => api("DELETE", `/app/api/dividas/${id}`)); break;
    case "exemplo": b.disabled = true; await acao(() => api("POST", "/app/api/exemplo", { empresa:S.empresa })); break;
    case "limpa-ex": b.disabled = true; await acao(() => api("DELETE", "/app/api/exemplo", { empresa:S.empresa })); break;
    case "dnew": S.divForm = {}; render(); $("#d-credor")?.focus(); break;
    case "dedit": if (it) { S.divForm = { ...it }; render(); $("#d-saldo")?.focus(); } break;
    case "dcancel": S.divForm = null; render(); break;
    case "estr": await acao(() => api("PUT", "/app/api/config", { empresa:S.empresa, estrategia:b.dataset.v })); break;
    case "usa-sobra": { const sim = simular(dividas(), 0, "avalanche"); const v = Math.round((sim.minimo + parseFloat(b.dataset.v))*100)/100; await acao(() => api("PUT", "/app/api/config", { empresa:S.empresa, orc:String(v).replace(".",",") })); break; }
    case "ai": if (BOOT.ia) pedirAnalise(); break;
  }
});
document.addEventListener("change", ev => {
  if (ev.target.id === "empresa") { S.empresa = ev.target.value; try { localStorage.setItem("wsf:empresa", S.empresa); } catch(e){} S.form = null; S.divForm = null; recarregarTudo(); }
  if (ev.target.id === "f-emp") { const f = lerForm(); S.form = { ...S.form, ...f, empresa:ev.target.value, conta:null }; render(); }
});
document.addEventListener("submit", async ev => {
  const form = ev.target; const kind = form.dataset.form; if (!kind) return;
  ev.preventDefault();
  if (kind === "quick") { const q = $("#q-txt"); const t = q.value; q.value = ""; await lancarTexto(t); $("#q-txt")?.focus(); return; }
  if (kind === "lanc") {
    const f = { ...S.form, ...lerForm() };
    if (!f.desc || !(f.valor > 0) || !f.data) { S.fb = { ok:false, t:"Preencha descrição, valor e data." }; return render(); }
    const emp = f.empresa || (consolidado() ? (BOOT.empresas[0]||{}).id : S.empresa);
    const btn = form.querySelector('[type="submit"]'); if (btn) btn.disabled = true;
    try {
      if (f.id) await api("PUT", `/app/api/lancamentos/${f.id}`, { desc:f.desc.trim(), valor:f.valor, data:f.data, cat:f.cat, pago:!!f.pago, conta:f.conta });
      else {
        const rep = String(f.rep||"1"), modo = rep.startsWith("p") ? "parcelada" : "fixa", n = parseInt(rep.replace(/\D/g,""),10) || 1;
        await api("POST", "/app/api/lancamentos", { empresa:emp, tipo:f.tipo, desc:f.desc.trim(), valor:f.valor, cat:f.cat, data:f.data, pago: f.tipo==="despesa" ? !!f.pago : true, repetir:n, modo, conta:f.conta });
        S.mes = f.data.slice(0,7);
      }
      S.form = null; S.fb = { ok:true, t: f.id ? "Lançamento salvo." : `${empresaNome(emp) && consolidado() ? empresaNome(emp)+": " : ""}lançamento salvo.` };
    } catch(e) { S.fb = { ok:false, t:e.message }; if (btn) btn.disabled = false; render(); return; }
    S.fluxo = null; S.rel = null; await carregar(); return;
  }
  if (kind === "div") {
    const body = { credor:$("#d-credor").value.trim(), saldo:$("#d-saldo").value, juros:$("#d-juros").value, parcela:$("#d-parc").value };
    if (!body.credor || isNaN(parseValor(body.saldo)) || isNaN(parseValor(body.juros)) || isNaN(parseValor(body.parcela))) return;
    const id = S.divForm.id; S.divForm = null;
    await acao(() => id ? api("PUT", `/app/api/dividas/${id}`, body) : api("POST", "/app/api/dividas", { ...body, empresa: $("#d-emp")?.value || S.empresa }));
    return;
  }
  if (kind === "cfg") { const v = $("#c-orc").value; await acao(() => api("PUT", "/app/api/config", { empresa:S.empresa, orc: v || "0" })); return; }
  if (kind === "rel") { S.relDe = $("#r-de").value || MES_ATUAL; S.relAte = $("#r-ate").value || S.relDe; if (S.relAte < S.relDe) S.relAte = S.relDe; S.rel = null; render(); }
});

const sel = $("#empresa"); if (sel) sel.value = S.empresa;
render();
carregar();
})();
