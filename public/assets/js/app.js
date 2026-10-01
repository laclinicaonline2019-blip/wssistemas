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
    if (ev.defaultPrevented) return;
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // Evita duplo envio. Desabilita só depois que o navegador montou os dados do
    // formulário — botão desabilitado não envia seu name/value (ex.: tipo de senha).
    var form = ev.target;
    setTimeout(function () { $$('button[type=submit]', form).forEach(function (b) { b.disabled = true; }); }, 0);
  });

  // Auto-submit (ex.: seletor de filial)
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]')) ev.target.form.submit();
  });

  // Barras de progresso (sem style inline por causa da CSP)
  $$('[data-pct]').forEach(function (el) { el.style.width = Math.max(0, Math.min(100, +el.getAttribute('data-pct'))) + '%'; });

  // Atualização automática (fila da recepção) — não recarrega enquanto o usuário digita/seleciona.
  var auto = $('[data-autorefresh]');
  if (auto) {
    var seconds = +auto.getAttribute('data-autorefresh') || 20;
    setInterval(function () {
      var a = document.activeElement;
      var busy = a && (a.tagName === 'INPUT' || a.tagName === 'SELECT' || a.tagName === 'TEXTAREA') || $('.dropdown.is-open');
      if (!busy && document.visibilityState === 'visible') window.location.reload();
    }, seconds * 1000);
  }

  // Agendamento: busca de paciente (JSON) e convênio
  var lookup = $('[data-patient-lookup]');
  if (lookup) {
    var q = $('#patient-q'), results = $('#patient-results'), hidden = $('#patient_id');
    var selected = $('#patient-selected'), insSelect = $('#patient_insurance_id'), timer = null;
    var render = function (items) {
      results.innerHTML = '';
      if (!items.length) { results.innerHTML = '<div class="lookup__item muted">Nenhum paciente encontrado.</div>'; }
      items.forEach(function (p) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'lookup__item';
        b.textContent = '#' + p.record_number + ' — ' + p.name + (p.birth_date ? ' · ' + p.birth_date : '') + (p.cpf ? ' · CPF ' + p.cpf : '');
        b.addEventListener('click', function () {
          hidden.value = p.id;
          $('#patient-selected-name').textContent = p.name;
          $('#patient-selected-info').textContent = '#' + p.record_number + (p.birth_date ? ' · ' + p.birth_date : '');
          selected.classList.remove('hidden'); q.classList.add('hidden'); results.classList.add('hidden');
          insSelect.innerHTML = '';
          p.insurances.forEach(function (i) {
            var o = document.createElement('option'); o.value = i.id; o.textContent = i.label + (i.expired ? ' (VENCIDA)' : '');
            insSelect.appendChild(o);
          });
        });
        results.appendChild(b);
      });
      results.classList.remove('hidden');
    };
    q.addEventListener('input', function () {
      clearTimeout(timer);
      if (q.value.trim().length < 2) { results.classList.add('hidden'); return; }
      timer = setTimeout(function () {
        fetch(lookup.getAttribute('data-patient-lookup') + '?q=' + encodeURIComponent(q.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { data: [] }; })
          .then(function (j) { render(j.data || []); });
      }, 250);
    });
    document.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-patient-clear]')) {
        hidden.value = ''; selected.classList.add('hidden'); q.classList.remove('hidden'); q.value = ''; q.focus();
      }
    });
    var payer = $('[data-payer]');
    var syncPayer = function () { $('#insurance-field').classList.toggle('hidden', payer.value !== 'insurance'); };
    payer.addEventListener('change', syncPayer);
  }

  // Prontuário: diagnósticos (CID), autosave do rascunho e proteção contra perda de dados.
  var enc = $('#encounter-form');
  if (enc) {
    var csrf = (enc.querySelector('input[name=_token]') || {}).value;
    var dxRows = $('[data-dx-rows]', enc), dxTpl = $('#dx-template'), dxEmpty = $('[data-dx-empty]', enc);
    var cidInput = $('[data-cid-search]', enc), cidResults = $('[data-cid-results]', enc);
    var dirty = false, saving = false, timer = null, pendingSubmit = null;
    var autosaveUrl = enc.getAttribute('data-autosave-url');
    var status = $('[data-save-status]'), revisionInput = $('[data-revision]', enc);
    var setStatus = function (txt, cls) { if (status) { status.textContent = txt; status.className = 'small ' + (cls || 'muted'); } };

    var syncPrimary = function () {
      var rows = $$('[data-dx-row]', dxRows);
      if (rows.length && !$$('input[name=dx_primary]:checked', dxRows).length) rows[0].querySelector('input[name=dx_primary]').checked = true;
      rows.forEach(function (r) { r.querySelector('[data-dx-primary]').value = r.querySelector('input[name=dx_primary]').checked ? '1' : '0'; });
      if (dxEmpty) dxEmpty.hidden = rows.length > 0;
    };
    var nextIndex = function () {
      var max = -1;
      $$('[data-dx-row] input[name=dx_primary]', dxRows).forEach(function (i) { max = Math.max(max, +i.value); });
      return max + 1;
    };
    var addDx = function (c) {
      if (!dxRows || !dxTpl) return;
      var exists = $$('input[name$="[cid_code_id]"]', dxRows).some(function (i) { return i.value === c.id; });
      if (exists) return;
      var idx = String(nextIndex());
      var frag = dxTpl.content.cloneNode(true);
      $$('[name]', frag).forEach(function (el) { el.name = el.name.replace('__I__', idx); });
      $$('input[name=dx_primary]', frag).forEach(function (el) { el.value = idx; });
      frag.querySelector('[data-dx-id]').value = c.id;
      frag.querySelector('[data-dx-code]').textContent = c.code;
      frag.querySelector('[data-dx-desc]').textContent = c.description;
      dxRows.appendChild(frag);
      syncPrimary(); markDirty();
    };

    if (cidInput) {
      var cidTimer = null;
      cidInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
      cidInput.addEventListener('input', function () {
        clearTimeout(cidTimer);
        var term = cidInput.value.trim();
        if (term.length < 2) { cidResults.classList.add('hidden'); return; }
        cidTimer = setTimeout(function () {
          fetch(enc.getAttribute('data-cid-url') + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { data: [] }; })
            .then(function (j) {
              cidResults.innerHTML = '';
              var items = j.data || [];
              if (!items.length) cidResults.innerHTML = '<div class="lookup__item muted">Nenhum CID encontrado.</div>';
              items.forEach(function (c) {
                var row = document.createElement('div'); row.className = 'lookup__row';
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'lookup__item';
                b.textContent = c.code + ' — ' + c.description;
                b.addEventListener('click', function () { addDx(c); cidInput.value = ''; cidResults.classList.add('hidden'); cidInput.focus(); });
                var fav = document.createElement('button');
                fav.type = 'button'; fav.className = 'lookup__fav' + (c.favorite ? ' is-on' : '');
                fav.textContent = c.favorite ? '★' : '☆'; fav.title = 'Favorito'; fav.setAttribute('aria-label', 'Favoritar ' + c.code);
                fav.addEventListener('click', function () {
                  fetch(enc.getAttribute('data-cid-favorite-url').replace('__ID__', c.id), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) { fav.textContent = res.favorite ? '★' : '☆'; fav.classList.toggle('is-on', res.favorite); });
                });
                row.appendChild(b); row.appendChild(fav); cidResults.appendChild(row);
              });
              cidResults.classList.remove('hidden');
            });
        }, 250);
      });
    }

    enc.addEventListener('click', function (ev) {
      var add = ev.target.closest('[data-cid-add]');
      if (add) { addDx({ id: add.getAttribute('data-id'), code: add.getAttribute('data-code'), description: add.getAttribute('data-description') }); return; }
      var rm = ev.target.closest('[data-dx-remove]');
      if (rm) { rm.closest('[data-dx-row]').remove(); syncPrimary(); markDirty(); return; }
      if (ev.target.closest('[data-save-now]')) { save(); }
    });
    enc.addEventListener('change', function (ev) { if (ev.target.name === 'dx_primary') syncPrimary(); });

    // Monta {seção: texto, diagnoses: [...], return_in_days} a partir dos campos data[...]
    var collect = function () {
      var data = {}, dx = {};
      $$('[name^="data["]', enc).forEach(function (el) {
        var parts = el.name.replace(/\]/g, '').split('[').slice(1);
        if (parts[0] === 'diagnoses') { (dx[parts[1]] = dx[parts[1]] || {})[parts[2]] = el.value; }
        else data[parts[0]] = el.value;
      });
      data.diagnoses = Object.keys(dx).map(function (k) { return dx[k]; });
      return data;
    };

    var save = function () {
      if (!autosaveUrl || saving) return Promise.resolve();
      clearTimeout(timer);
      saving = true; dirty = false; setStatus('Salvando…');
      return fetch(autosaveUrl, {
        method: 'PUT', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ revision: +revisionInput.value, data: collect() })
      }).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          if (r.ok) {
            revisionInput.value = j.revision;
            var d = new Date(j.saved_at);
            setStatus('Salvo às ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2), 'text-success');
          } else if (r.status === 409) {
            setStatus(j.message || 'Alterado em outra janela — recarregue a página.', 'text-danger'); autosaveUrl = null;
            window.alert(j.message || 'Este atendimento foi alterado em outra janela. Recarregue a página.');
          } else if (r.status === 419 || r.status === 401) {
            setStatus('Sessão expirada — faça login novamente (copie o texto antes).', 'text-danger'); dirty = true;
          } else { setStatus('Falha ao salvar — tentaremos de novo.', 'text-danger'); dirty = true; }
        });
      }).catch(function () { setStatus('Sem conexão — tentaremos de novo.', 'text-danger'); dirty = true; })
        .then(function () {
          saving = false;
          if (pendingSubmit) { var s = pendingSubmit; pendingSubmit = null; enc.requestSubmit(s === true ? undefined : s); }
          else if (dirty) schedule();
        });
    };
    var schedule = function () { clearTimeout(timer); timer = setTimeout(save, 8000); };
    function markDirty() { dirty = true; if (autosaveUrl) { setStatus('Alterações não salvas'); schedule(); } }

    enc.addEventListener('input', function (ev) { if (ev.target !== cidInput) markDirty(); });
    // Finalizar: garante que o último autosave terminou (a revisão enviada precisa ser a atual).
    enc.addEventListener('submit', function (ev) {
      if (saving) { ev.preventDefault(); pendingSubmit = ev.submitter || true; return; }
      clearTimeout(timer); dirty = false;
    });
    // Outros formulários (ex.: nova alergia) recarregam a página: salva o rascunho antes.
    $$('[data-save-first]').forEach(function (b) {
      b.form.addEventListener('submit', function (ev) {
        if (!autosaveUrl || (!dirty && !saving)) return;
        ev.preventDefault();
        var f = b.form;
        (saving ? new Promise(function (res) { var i = setInterval(function () { if (!saving) { clearInterval(i); res(); } }, 100); }) : Promise.resolve())
          .then(function () { return dirty ? save() : null; }).then(function () { f.submit(); });
      });
    });
    window.addEventListener('beforeunload', function (ev) { if (dirty || saving) { ev.preventDefault(); ev.returnValue = ''; } });
    syncPrimary();
  }

  // Documentos (Fase 6): receita com busca de medicamentos, seletor de CID, atestado e exames.
  var jsonGet = function (url) {
    return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : { data: [] }; });
  };
  var NOTIF = ['A1', 'A2', 'A3', 'B1', 'B2', 'C2', 'C3'], SPECIAL = ['antimicrobial', 'C1', 'C4', 'C5'];
  var rxHint = function (row) {
    var ctl = row.getAttribute('data-control') || 'none';
    var hint = $('[data-rx-hint]', row), notif = $('[data-rx-notification]', row);
    notif.classList.toggle('hidden', NOTIF.indexOf(ctl) < 0);
    hint.textContent = NOTIF.indexOf(ctl) >= 0 ? 'Lista ' + ctl + ': exige Notificação de Receita oficial (talão). Informe o número e a quantidade — não sai na receita impressa.'
      : SPECIAL.indexOf(ctl) >= 0 ? 'Controle especial (' + (ctl === 'antimicrobial' ? 'antimicrobiano' : ctl) + '): sai em receita de controle especial, 2 vias. Quantidade obrigatória.' : '';
  };
  var rxForm = $('[data-rx-form]');
  if (rxForm) {
    var rows = $('[data-rx-rows]', rxForm), tpl = $('#rx-template'), seq = 1000;
    var addRow = function (m) {
      var frag = tpl.content.cloneNode(true), idx = String(seq++);
      $$('[name]', frag).forEach(function (el) { el.name = el.name.replace('__I__', idx); });
      var row = frag.querySelector('[data-rx-row]');
      if (m) {
        $('[data-rx-med-id]', row).value = m.id;
        var name = $('[data-rx-name]', row); name.value = m.label; name.readOnly = true;
        var ctl = $('[data-rx-control]', row); ctl.value = m.control_type; ctl.disabled = true;
        $('[data-rx-posology]', row).value = m.default_posology || '';
        $('[data-rx-route]', row).value = m.route || '';
        row.setAttribute('data-control', m.control_type);
      }
      // remove a linha vazia inicial
      $$('[data-rx-row]', rows).forEach(function (r) {
        if (!$('[data-rx-med-id]', r).value && !$('[data-rx-name]', r).value && !$('[data-rx-posology]', r).value) r.remove();
      });
      rows.appendChild(frag); rxHint(row);
      (m ? $('[data-rx-quantity]', row) : $('[data-rx-name]', row)).focus();
    };
    $$('[data-rx-row]', rows).forEach(rxHint);
    var mq = $('[data-med-q]', rxForm), ml = $('[data-med-list]', rxForm), mt = null;
    mq.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    mq.addEventListener('input', function () {
      clearTimeout(mt);
      var term = mq.value.trim();
      if (term.length < 2) { ml.classList.add('hidden'); return; }
      mt = setTimeout(function () {
        jsonGet(rxForm.getAttribute('data-med-url') + '?q=' + encodeURIComponent(term)).then(function (j) {
          ml.innerHTML = '';
          var items = j.data || [];
          if (!items.length) ml.innerHTML = '<div class="lookup__item muted">Nenhum medicamento na base — use "item digitado manualmente".</div>';
          items.forEach(function (m) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'lookup__item';
            b.textContent = m.label + (m.controlled ? '  [' + (m.control_type === 'antimicrobial' ? 'antimicrobiano' : m.control_type) + ']' : '');
            b.addEventListener('click', function () { addRow(m); mq.value = ''; ml.classList.add('hidden'); });
            ml.appendChild(b);
          });
          ml.classList.remove('hidden');
        });
      }, 250);
    });
    rxForm.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-rx-add]')) addRow(null);
      var rm = ev.target.closest('[data-rx-remove]');
      if (rm) { rm.closest('[data-rx-row]').remove(); if (!$$('[data-rx-row]', rows).length) addRow(null); }
    });
    rxForm.addEventListener('change', function (ev) {
      if (ev.target.matches('[data-rx-control]')) { var r = ev.target.closest('[data-rx-row]'); r.setAttribute('data-control', ev.target.value); rxHint(r); }
    });
  }

  $$('[data-cid-picker]').forEach(function (box) {
    var q = $('[data-cid-q]', box), list = $('[data-cid-list]', box), val = $('[data-cid-value]', box);
    var sel = $('[data-cid-selected]', box), wrap = $('[data-cid-search-wrap]', box), t = null;
    q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    q.addEventListener('input', function () {
      clearTimeout(t);
      if (q.value.trim().length < 2) { list.classList.add('hidden'); return; }
      t = setTimeout(function () {
        jsonGet(box.getAttribute('data-cid-picker') + '?q=' + encodeURIComponent(q.value.trim())).then(function (j) {
          list.innerHTML = '';
          (j.data || []).forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'lookup__item'; b.textContent = c.code + ' — ' + c.description;
            b.addEventListener('click', function () {
              val.value = c.id; $('[data-cid-label]', box).textContent = c.code + ' — ' + c.description;
              sel.classList.remove('hidden'); wrap.classList.add('hidden'); list.classList.add('hidden'); q.value = '';
            });
            list.appendChild(b);
          });
          list.classList.remove('hidden');
        });
      }, 250);
    });
    box.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-cid-clear]')) { val.value = ''; sel.classList.add('hidden'); wrap.classList.remove('hidden'); q.focus(); }
    });
  });

  // Mostra/oculta blocos por opção (ex.: atestado afastamento × comparecimento)
  var syncToggles = function () {
    $$('[data-toggle-show]').forEach(function (el) {
      var parts = el.getAttribute('data-toggle-show').split(':');
      var checked = $('[data-toggle-group="' + parts[0] + '"]:checked');
      var on = checked && checked.value === parts[1];
      el.classList.toggle('hidden', !on);
      $$('input, select, textarea', el).forEach(function (i) { i.disabled = !on; });
    });
  };
  if ($('[data-toggle-show]')) { syncToggles(); document.addEventListener('change', function (ev) { if (ev.target.matches('[data-toggle-group]')) syncToggles(); }); }

  // Acrescenta uma linha a um textarea (ex.: exames comuns)
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-append-line]');
    if (!b) return;
    var ta = $(b.getAttribute('data-append-line')), v = b.getAttribute('data-value');
    var lines = ta.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    if (lines.indexOf(v) < 0) lines.push(v);
    ta.value = lines.join('\n') + '\n'; ta.focus();
  });

  // Recebimento: campos de cartão/dinheiro conforme a forma e cálculo do troco.
  var rf = $('[data-receive-form]');
  if (rf) {
    var toCents = function (v) { var d = String(v || '').replace(/\D/g, ''); return d ? parseInt(d, 10) : 0; };
    var fmt = function (c) { return 'R$ ' + (c / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
    var syncReceive = function () {
      var m = $('[data-method]', rf).value;
      $('[data-card-fields]', rf).classList.toggle('hidden', ['credit_card', 'debit_card', 'pix'].indexOf(m) < 0);
      $('[name=card_installments]', rf).closest('.field').classList.toggle('hidden', m !== 'credit_card');
      $('[name=card_brand]', rf).closest('.field').classList.toggle('hidden', m === 'pix');
      $('[data-cash-fields]', rf).classList.toggle('hidden', m !== 'cash');
      var given = toCents($('[data-given]', rf).value), amount = toCents($('[name=amount]', rf).value);
      $('[data-change]', rf).textContent = given ? (given >= amount ? fmt(given - amount) : 'valor insuficiente') : '—';
    };
    rf.addEventListener('change', syncReceive); rf.addEventListener('input', syncReceive); syncReceive();
  }

  // Página de teste de impressão abre o diálogo automaticamente
  if (document.body.hasAttribute('data-autoprint')) {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
  }

  // Máscaras simples (CNPJ, CEP, telefone) — o backend normaliza/valida.
  var masks = {
    cpf: function (v) { return v.replace(/\D/g, '').slice(0, 11).replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2'); },
    cnpj: function (v) { return v.replace(/\D/g, '').slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2'); },
    cep: function (v) { return v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'); },
    money: function (v) {
      var d = v.replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 12);
      if (!d) return '';
      while (d.length < 3) d = '0' + d;
      var int = d.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
      return int + ',' + d.slice(-2);
    },
    phone: function (v) { v = v.replace(/\D/g, '').slice(0, 11); return v.length > 10 ? v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3') : v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3'); }
  };
  document.addEventListener('input', function (ev) {
    var m = ev.target.getAttribute && ev.target.getAttribute('data-mask');
    if (m && masks[m]) ev.target.value = masks[m](ev.target.value);

    // Endereço pelo CEP (consulta feita pelo servidor; falha não bloqueia o preenchimento manual)
    if (ev.target.hasAttribute && ev.target.hasAttribute('data-cep-lookup')) {
      var cep = ev.target.value.replace(/\D/g, '');
      if (cep.length !== 8 || ev.target.getAttribute('data-last') === cep) return;
      ev.target.setAttribute('data-last', cep);
      var base = (document.querySelector('meta[name=app-url]') || {}).content || '';
      var form = ev.target.form;
      fetch(base.replace(/\/$/, '') + '/cep/' + cep, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (a) {
          if (!a || !form) return;
          ['street', 'district', 'city', 'state'].forEach(function (k) {
            var field = form.querySelector('[name=' + k + ']');
            if (field && a[k] && !field.value) field.value = a[k];
          });
          var number = form.querySelector('[name=number]');
          if (number) number.focus();
        })
        .catch(function () { /* preenchimento manual */ });
    }
  });
})();
