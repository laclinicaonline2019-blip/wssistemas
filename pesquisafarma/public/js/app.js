// Pesquisa Farma — pequenos utilitários (sem build)
document.addEventListener('click', (e) => {
  const add = e.target.closest('[data-add-row]');
  if (add) {
    e.preventDefault();
    const tpl = document.getElementById(add.dataset.addRow);
    const target = document.querySelector(add.dataset.target);
    const idx = Date.now();
    target.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__i__', idx));
  }
  const rm = e.target.closest('[data-remove-row]');
  if (rm) { e.preventDefault(); rm.closest(rm.dataset.removeRow).remove(); }
  const all = e.target.closest('[data-check-all]');
  if (all) { document.querySelectorAll(all.dataset.checkAll).forEach((c) => (c.checked = all.checked)); }
  const copy = e.target.closest('[data-copy]');
  if (copy) { e.preventDefault(); navigator.clipboard.writeText(document.querySelector(copy.dataset.copy).value); copy.textContent = 'Copiado ✓'; }
  const conf = e.target.closest('[data-confirm]');
  if (conf && !confirm(conf.dataset.confirm)) { e.preventDefault(); }
});

// Atualiza a tela da cotação quando chegam respostas das farmácias
(function () {
  const el = document.querySelector('[data-poll]');
  if (!el) return;
  let sig = null;
  setInterval(async () => {
    try {
      const r = await fetch(el.dataset.poll, { headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (sig && j.signature !== sig && !document.querySelector('form :focus')) location.reload();
      sig = j.signature;
      const live = document.getElementById('live-status');
      if (live) live.textContent = `${j.answered} resposta(s) · ${j.pending} aguardando`;
    } catch (e) {}
  }, 8000);
})();

// Rola o chat para o fim
document.querySelectorAll('.chat').forEach((c) => (c.scrollTop = c.scrollHeight));
