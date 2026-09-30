/*
 * AivexaClínica — comportamento da interface (JS puro, sem inline scripts:
 * compatível com a Content-Security-Policy "script-src 'self'").
 * Toda regra de segurança/negócio é validada no backend; este arquivo só
 * melhora a experiência.
 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Tema (claro/escuro/sistema) — preferência apenas local.
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme);
    else document.documentElement.removeAttribute('data-theme');
  }
  try { applyTheme(localStorage.getItem('aivexa.theme')); } catch (e) { /* storage indisponível */ }

  document.addEventListener('click', function (ev) {
    var t = ev.target;

    // Menu lateral (mobile)
    var toggle = t.closest('[data-nav-toggle]');
    if (toggle) { $('.app').classList.toggle('nav-open'); return; }
    if ($('.app.nav-open') && !t.closest('.sidebar')) { $('.app').classList.remove('nav-open'); }

    // Dropdowns
    var dd = t.closest('[data-dropdown-toggle]');
    $$('.dropdown.is-open').forEach(function (el) { if (!dd || el !== dd.parentElement) el.classList.remove('is-open'); });
    if (dd) { dd.parentElement.classList.toggle('is-open'); dd.setAttribute('aria-expanded', dd.parentElement.classList.contains('is-open')); }

    // Tema
    var themeBtn = t.closest('[data-theme-set]');
    if (themeBtn) {
      var theme = themeBtn.getAttribute('data-theme-set');
      try { localStorage.setItem('aivexa.theme', theme); } catch (e) { /* ignore */ }
      applyTheme(theme);
    }

    // Linhas dinâmicas (ex.: perfis do usuário)
    var add = t.closest('[data-row-add]');
    if (add) {
      var container = $(add.getAttribute('data-row-add'));
      var tpl = $(add.getAttribute('data-row-template'));
      var index = Date.now();
      container.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__INDEX__/g, index));
    }
    var remove = t.closest('[data-row-remove]');
    if (remove) { remove.closest('[data-row]').remove(); }

    // Selecionar todas as permissões de um módulo
    var all = t.closest('[data-check-all]');
    if (all) {
      var box = all.closest('.perm-module');
      var boxes = $$('input[type=checkbox]:not([disabled])', box);
      var shouldCheck = boxes.some(function (b) { return !b.checked; });
      boxes.forEach(function (b) { b.checked = shouldCheck; });
    }

    // Copiar texto
    var copy = t.closest('[data-copy]');
    if (copy && navigator.clipboard) {
      navigator.clipboard.writeText($(copy.getAttribute('data-copy')).innerText.trim());
      copy.textContent = 'Copiado!';
    }

    // Impressão
    if (t.closest('[data-print]')) { window.print(); }
  });

  // Confirmação antes de ações sensíveis: <form data-confirm="...">
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // Evita duplo envio
    $$('button[type=submit]', ev.target).forEach(function (b) { b.disabled = true; });
  });

  // Auto-submit (ex.: seletor de filial)
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]')) ev.target.form.submit();
  });

  // Barras de progresso (sem style inline por causa da CSP)
  $$('[data-pct]').forEach(function (el) { el.style.width = Math.max(0, Math.min(100, +el.getAttribute('data-pct'))) + '%'; });

  // Página de teste de impressão abre o diálogo automaticamente
  if (document.body.hasAttribute('data-autoprint')) {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
  }

  // Máscaras simples (CNPJ, CEP, telefone) — o backend normaliza/valida.
  var masks = {
    cnpj: function (v) { return v.replace(/\D/g, '').slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2'); },
    cep: function (v) { return v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'); },
    phone: function (v) { v = v.replace(/\D/g, '').slice(0, 11); return v.length > 10 ? v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3') : v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3'); }
  };
  document.addEventListener('input', function (ev) {
    var m = ev.target.getAttribute && ev.target.getAttribute('data-mask');
    if (m && masks[m]) ev.target.value = masks[m](ev.target.value);
  });
})();
