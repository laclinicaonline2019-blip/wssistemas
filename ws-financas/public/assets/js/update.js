// Executa as etapas da atualização uma a uma (cada etapa é uma requisição curta no servidor).
(() => {
  const box = document.getElementById("update");
  if (!box) return;
  const url = box.dataset.stepUrl;
  const token = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const status = document.getElementById("up-status"), log = document.getElementById("up-log"), msg = document.getElementById("up-msg");
  const names = {}; document.querySelectorAll("#up-steps li").forEach(li => names[li.dataset.step] = li.children[1].textContent);
  const label = { running:"em andamento", done:"concluída", failed:"falhou — nada foi alterado", failed_rolled_back:"falhou — versão anterior restaurada", failed_manual:"falhou — reversão manual necessária", reverted:"revertida" };
  const show = (text, kind) => { msg.hidden = false; msg.className = "banner " + kind; msg.textContent = text; };
  let errors = 0;
  async function tick(){
    let d;
    try {
      const r = await fetch(url, { method:"POST", headers:{ "X-CSRF-TOKEN":token, "Accept":"application/json", "X-Requested-With":"XMLHttpRequest" }, credentials:"same-origin" });
      if (r.status === 419 || r.status === 401) { show("Sua sessão expirou. Entre novamente e abra esta página para continuar.", "bad"); return; }
      d = await r.json();
    } catch (e) {
      if (++errors > 20) { show("Sem resposta do servidor. Recarregue a página para continuar de onde parou.", "bad"); return; }
      return setTimeout(tick, 3000);
    }
    errors = 0;
    (d.done || []).forEach(s => { const li = document.querySelector(`#up-steps li[data-step="${s}"] [data-mark]`); if (li) li.textContent = "✓"; });
    log.textContent = (d.log || []).map(l => `${l.at} ${l.ok ? "✓" : "✗"} ${names[l.step] || l.step} — ${l.msg}`).join("\n");
    status.textContent = label[d.status] || d.status;
    status.className = "pill " + (d.status === "done" ? "ok" : d.status === "running" ? "warn" : "bad");
    if (d.status === "running") { if (!d.busy) show("Executando: " + (names[d.next] || d.next) + "…", "info"); return setTimeout(tick, d.busy ? 2500 : 300); }
    if (d.status === "done") show(`Pronto! Sistema atualizado para a versão ${d.to}.`, "good");
    else show((label[d.status] || d.status) + (d.error ? ": " + d.error : ""), "bad");
  }
  if (box.dataset.status === "running") tick();
})();
