// Pequenos comportamentos das telas (sem scripts inline: a CSP bloqueia).
document.addEventListener("change", e => { const s = e.target.closest("[data-autosubmit]"); if (s) s.form.submit(); });
document.addEventListener("submit", e => {
  const f = e.target.closest("[data-confirm]");
  if (f && !confirm(f.dataset.confirm)) e.preventDefault();
});
document.addEventListener("click", e => {
  const b = e.target.closest("[data-toggle-all]");
  if (!b) return;
  const box = document.getElementById(b.dataset.toggleAll); if (box) box.hidden = b.checked;
});
