// Cenários dos testes ponta a ponta. Cada um usa só a interface (como um usuário real).
import { fileURLToPath } from 'node:url';

const PASS = 'Demo@12345';
const FIX = fileURLToPath(new URL('./fixtures/', import.meta.url));

async function expectText(page, text) {
  await page.getByText(text, { exact: false }).first().waitFor({ timeout: 10000 });
}

export async function login(page, base, email) {
  await page.goto(base + '/login');
  await page.fill('#email', email);
  await page.fill('#password', PASS);
  await nav(page, () => page.click('button[type=submit]'));
}

/** Recarrega até o texto aparecer (respostas geradas depois do 200, como a da IA). */
async function eventually(page, text, tries = 10) {
  for (let i = 0; i < tries; i++) {
    if (await page.getByText(text, { exact: false }).count()) return;
    await page.waitForTimeout(800);
    await page.reload({ waitUntil: 'networkidle' });
  }
  throw new Error('Texto não apareceu: ' + text);
}

/** Clica (ou age) e espera a PRÓXIMA página carregar por completo (evita agir na página antiga). */
export async function nav(page, action) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), action()]);
}

async function pause(page, ms = 900) { if (process.env.RECORD) await page.waitForTimeout(ms); }

export const scenarios = {
  // Recepção: cadastra paciente, agenda no primeiro horário livre, registra chegada e emite senha.
  async recepcao(page, { base }) {
    await login(page, base, 'recepcao@demo.aivexa.local');
    await expectText(page, 'Dashboard');
    await pause(page);
    await page.goto(base + '/pacientes/novo');
    const stamp = String(Date.now()).slice(-6);
    await page.fill('#f-name', `Paciente Teste E2E ${stamp}`);
    await page.fill('#f-birth_date', '1990-05-20');
    await page.selectOption('#f-sex', { index: 1 });
    await page.fill('#f-whatsapp', '11987' + stamp);
    await pause(page, 500);
    await nav(page, () => page.click('button:has-text("Cadastrar paciente")'));
    await expectText(page, `Paciente Teste E2E ${stamp}`);
    await pause(page);

    await page.goto(base + '/agenda');
    const carla = await page.locator('#doctor_id option', { hasText: 'Carla' }).first().getAttribute('value');
    await page.selectOption('#doctor_id', carla);
    await nav(page, () => page.click('button:has-text("Ver agenda")'));
    for (let i = 0; i < 10 && !(await page.locator('a:has-text("+ agendar")').count()); i++) {
      await nav(page, () => page.click('a[aria-label="Próximo dia"]'));
    }
    await pause(page);
    await nav(page, () => page.locator('a:has-text("+ agendar")').first().click());
    page.on('response', async r => { if (process.env.DEBUG && r.url().includes('busca')) console.log('   busca', r.status(), (await r.text()).slice(0, 200)); });
    await page.fill('#patient-q', `Paciente Teste E2E ${stamp}`);
    await page.locator('#patient-results button.lookup__item', { hasText: stamp }).first().click();
    await pause(page, 600);
    await nav(page, () => page.click('#booking-form button[type=submit]'));
    await expectText(page, `Paciente Teste E2E ${stamp}`);
    await pause(page);
    if (await page.locator('button:has-text("Registrar chegada")').count()) {
      await nav(page, () => page.click('button:has-text("Registrar chegada")'));
      await pause(page);
    }
    await page.goto(base + '/fila');
    await expectText(page, 'Fila');
    await pause(page, 1500);
  },

  // Médico: atende o paciente que chegou (cenário "recepcao"), registra o prontuário, finaliza e pede exames.
  async medico(page, { base }) {
    await login(page, base, 'medica@demo.aivexa.local');
    await page.goto(base + '/atendimento');
    await expectText(page, 'Paciente Teste E2E');
    await pause(page);
    const row = page.locator('tr', { hasText: 'Paciente Teste E2E' }).last();
    const start = row.locator('button:has-text("Iniciar atendimento"), a:has-text("Continuar")').first();
    await nav(page, () => start.click());
    await page.fill('#f-chief_complaint', 'Dor no peito aos esforços há 2 semanas, sem irradiação.');
    await page.fill('#f-conduct', 'Solicitados exames laboratoriais e ECG. Retorno com resultados.');
    if (await page.locator('#f-physical_exam').count()) await page.fill('#f-physical_exam', 'BEG, PA 130x85 mmHg, FC 78 bpm. Ausculta cardíaca sem alterações.');
    await page.fill('#cid-q', 'I20');
    const opt = page.locator('[data-cid-results] [role=option], [data-cid-results] button').first();
    if (await opt.waitFor({ timeout: 4000 }).then(() => true).catch(() => false)) await opt.click();
    await pause(page);
    page.once('dialog', d => d.accept());
    await page.locator('button:has-text("Finalizar atendimento")').click();
    await page.waitForLoadState('networkidle');
    for (let i = 0; i < 20 && page.url().includes('/editar'); i++) await page.waitForTimeout(250);
    await expectText(page, 'Finalizado');
    await pause(page);
    await nav(page, () => page.click('a:has-text("Exames")'));
    await page.fill('#f-exams', 'Hemograma completo\nGlicemia de jejum\nPerfil lipídico\nEletrocardiograma');
    await page.fill('#f-ind', 'Investigação de dor torácica');
    await pause(page, 600);
    await nav(page, () => page.click('button:has-text("Emitir e imprimir")'));
    await expectText(page, 'Documento emitido');
    await pause(page, 2000);
  },

  // Financeiro: abre o caixa, recebe a consulta (PIX), exporta relatório em Excel e concilia o extrato OFX.
  async financeiro(page, { base }) {
    await login(page, base, 'financeiro@demo.aivexa.local');
    await page.goto(base + '/caixa');
    if (await page.locator('button:has-text("Abrir caixa")').count()) {
      await page.fill('#f-opening', '100,00');
      await nav(page, () => page.click('button:has-text("Abrir caixa")'));
    }
    await pause(page);
    await page.goto(base + '/contas-a-receber?q=Paciente+Teste+E2E&status=open');
    await pause(page);
    const receive = page.locator('tr', { hasText: 'Paciente Teste E2E' }).locator('a.btn-primary').first();
    if (await receive.count()) {
      await nav(page, () => receive.click());
      await page.selectOption('#rv-method', 'pix');
      await pause(page, 600);
      await nav(page, () => page.click('button:has-text("Registrar recebimento")'));
      await pause(page);
    }
    await page.goto(base + '/relatorios');
    await expectText(page, 'Relatórios');
    await pause(page);
    await nav(page, () => page.click('a:has-text("Receitas e despesas por categoria")'));
    await pause(page);
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Excel")')]);
    if (!(await download.suggestedFilename()).endsWith('.xlsx')) throw new Error('Excel não gerado');

    await page.goto(base + '/financeiro/conciliacao');
    if (!(await page.locator('a:has-text("Abrir")').count())) {
      await page.fill('#f-name', 'Itaú — conta movimento (demo)');
      await page.fill('#f-bank_code', '341');
      await nav(page, () => page.click('button:has-text("Cadastrar conta")'));
    } else {
      await nav(page, () => page.locator('a:has-text("Abrir")').first().click());
    }
    await page.setInputFiles('#sf', FIX + 'extrato.ofx');
    await pause(page, 500);
    await nav(page, () => page.click('button:has-text("Importar")'));
    await expectText(page, 'Extrato importado');
    await pause(page);
    const tarifa = page.locator('tr', { hasText: 'TARIFA PACOTE' }).locator('a:has-text("Resolver")');
    if (await tarifa.count()) {
      await nav(page, () => tarifa.click());
      await pause(page);
      await nav(page, () => page.click('button:has-text("Lançar e conciliar")'));
      await expectText(page, 'conciliado');
    }
    await pause(page, 1500);
  },

  // WhatsApp + IA (MOCK): paciente pede agendamento, envia foto de pedido de exame (lida pela IA, não verificada),
  // a equipe confere o documento e o paciente pede atendente (conversa passa para a equipe).
  async whatsapp(page, { base }) {
    await login(page, base, 'admin@demo.aivexa.local');
    await page.goto(base + '/conversas');
    await pause(page);
    const thread = base + (new URL(await page.locator('a:has-text("Joana")').first().getAttribute('href'))).pathname;
    const simulate = async (text, file) => {
      await page.goto(thread, { waitUntil: 'networkidle' });
      await page.fill('#sm', text);
      if (file) await page.setInputFiles('#sf', FIX + file);
      await pause(page, 600);
      await nav(page, () => page.click('button:has-text("Simular mensagem recebida")'));
      await page.goto(thread, { waitUntil: 'networkidle' });
    };
    await page.goto(thread, { waitUntil: 'networkidle' });
    await pause(page);
    await simulate('Quero agendar uma consulta com cardiologista');
    await eventually(page, 'Posso ajudar a agendar');
    await pause(page, 1500);
    await simulate('Segue o pedido de exame do meu médico', 'pedido-exame.png');
    await eventually(page, 'NÃO VERIFICADO');
    await pause(page, 1500);
    await nav(page, () => page.locator('a:has-text("Conferir leitura")').last().click());
    await expectText(page, 'Leitura automática');
    await pause(page, 1500);
    await nav(page, () => page.click('button:has-text("Marcar como conferido")'));
    await expectText(page, 'Documento conferido');
    await pause(page);
    await simulate('Prefiro falar com um atendente');
    await eventually(page, 'Pausada');
    await pause(page, 1500);
  },

  // Portal do paciente: a recepção gera o link, o paciente cria a senha, vê o pedido de exames e agenda online.
  async portal(page, { base }) {
    await login(page, base, 'admin@demo.aivexa.local');
    await page.goto(base + '/pacientes?q=Paciente+Teste+E2E');
    await nav(page, () => page.locator('a:has-text("Abrir")').first().click());
    await pause(page);
    await page.fill('#f-email', `paciente.e2e.${Date.now()}@demo.aivexa.local`);
    await nav(page, () => page.click('button:has-text("Gerar link")'));
    const link = await page.inputValue('#portal-link');
    await pause(page, 1200);
    await page.context().clearCookies();
    await page.goto(link, { waitUntil: 'networkidle' });
    await pause(page);
    await page.fill('#password', 'Portal@Senha2026');
    await page.fill('#password_confirmation', 'Portal@Senha2026');
    await page.check('input[name=terms]');
    await nav(page, () => page.click('button:has-text("Salvar senha e entrar")'));
    await expectText(page, 'Próximas consultas');
    await pause(page, 1200);
    if (await page.locator('a:has-text("Ver todos")').count()) {
      await nav(page, () => page.locator('a[href*="documentos"]').first().click());
      await pause(page, 1200);
    }
    await nav(page, () => page.locator('a:has-text("Agendar")').first().click());
    const horarios = page.locator('a:has-text("Ver horários")');
    if (await horarios.count()) await nav(page, () => horarios.first().click());
    await pause(page);
    await nav(page, () => page.click('button:has-text("Confirmar agendamento")'));
    await expectText(page, 'agendad');
    await pause(page, 1500);
  },

  // Gestão da clínica e plataforma: painel, relatório, segurança, assinatura; super admin vê clínicas e MRR.
  async gestao(page, { base }) {
    await login(page, base, 'admin@demo.aivexa.local');
    await expectText(page, 'Dashboard');
    await pause(page, 1500);
    await page.goto(base + '/relatorios/doctor_production', { waitUntil: 'networkidle' });
    await expectText(page, 'Produção por médico');
    await pause(page, 1500);
    await page.goto(base + '/seguranca', { waitUntil: 'networkidle' });
    await expectText(page, 'Central de segurança');
    await pause(page, 1500);
    await page.goto(base + '/atendimento-ia', { waitUntil: 'networkidle' });
    await expectText(page, 'Recepcionista virtual');
    await pause(page, 1500);
    await page.goto(base + '/assinatura', { waitUntil: 'networkidle' });
    await expectText(page, 'Assinatura da aivexaclinica');
    await pause(page, 1500);
    await page.context().clearCookies();
    await login(page, base, 'superadmin@demo.aivexa.local');
    // Segurança: o super admin só usa a plataforma depois de ativar a verificação em duas etapas.
    await page.goto(base + '/plataforma/assinaturas', { waitUntil: 'networkidle' });
    await expectText(page, 'dois fatores');
    await pause(page, 1500);
  },
};
