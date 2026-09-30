<?php

/*
|--------------------------------------------------------------------------
| Catálogo de permissões (RBAC)
|--------------------------------------------------------------------------
|
| Fonte única da verdade para as permissões granulares do sistema.
| O comando `php artisan aivexa:permissions:sync` sincroniza este catálogo
| com a tabela `permissions`. Nunca crie permissões diretamente no banco.
|
| scope = tenant   → concedidas via perfis (roles) da clínica
| scope = platform → exclusivas do Super Admin da plataforma SaaS
|
| As permissões de módulos de fases futuras já constam no catálogo para que
| os perfis possam ser modelados desde o início; elas só passam a proteger
| rotas quando o módulo correspondente é entregue.
|
*/

return [

    'modules' => [

        'dashboard' => [
            'label' => 'Dashboard',
            'permissions' => [
                'dashboard.visualizar' => 'Visualizar dashboard',
                'dashboard.financeiro' => 'Visualizar indicadores financeiros no dashboard',
            ],
        ],

        'empresa' => [
            'label' => 'Empresa',
            'permissions' => [
                'empresa.visualizar' => 'Visualizar dados da empresa',
                'empresa.editar' => 'Editar dados e configurações da empresa',
            ],
        ],

        'filial' => [
            'label' => 'Filiais',
            'permissions' => [
                'filial.visualizar' => 'Visualizar filiais',
                'filial.criar' => 'Criar filiais',
                'filial.editar' => 'Editar filiais',
                'filial.desativar' => 'Desativar filiais',
            ],
        ],

        'usuario' => [
            'label' => 'Usuários',
            'permissions' => [
                'usuario.visualizar' => 'Visualizar usuários',
                'usuario.criar' => 'Criar usuários',
                'usuario.editar' => 'Editar usuários',
                'usuario.bloquear' => 'Bloquear/desbloquear usuários',
                'usuario.perfis' => 'Atribuir perfis a usuários',
            ],
        ],

        'perfil' => [
            'label' => 'Perfis e permissões',
            'permissions' => [
                'perfil.visualizar' => 'Visualizar perfis de acesso',
                'perfil.gerenciar' => 'Criar e editar perfis de acesso',
            ],
        ],

        'auditoria' => [
            'label' => 'Auditoria',
            'permissions' => [
                'auditoria.visualizar' => 'Visualizar trilha de auditoria',
                'auditoria.exportar' => 'Exportar trilha de auditoria',
            ],
        ],

        'paciente' => [
            'label' => 'Pacientes',
            'permissions' => [
                'paciente.visualizar' => 'Visualizar pacientes',
                'paciente.criar' => 'Cadastrar pacientes',
                'paciente.editar' => 'Editar pacientes',
                'paciente.exportar' => 'Exportar dados de pacientes (LGPD)',
                'paciente.anonimizar' => 'Anonimizar pacientes (LGPD)',
            ],
        ],

        'medico' => [
            'label' => 'Médicos e especialidades',
            'permissions' => [
                'medico.visualizar' => 'Visualizar médicos',
                'medico.gerenciar' => 'Cadastrar e editar médicos',
                'especialidade.gerenciar' => 'Gerenciar especialidades',
            ],
        ],

        'agenda' => [
            'label' => 'Agenda',
            'permissions' => [
                'agenda.visualizar' => 'Visualizar agenda',
                'agenda.criar' => 'Criar agendamentos',
                'agenda.editar' => 'Editar agendamentos',
                'agenda.cancelar' => 'Cancelar agendamentos',
                'agenda.encaixe' => 'Realizar encaixes',
                'agenda.configurar' => 'Configurar grades de horário e bloqueios',
            ],
        ],

        'fila' => [
            'label' => 'Fila e senhas',
            'permissions' => [
                'fila.visualizar' => 'Visualizar fila de atendimento',
                'fila.gerenciar' => 'Registrar chegada, gerar e chamar senhas',
                'fila.painel' => 'Configurar painel de chamadas',
            ],
        ],

        'triagem' => [
            'label' => 'Triagem / Enfermagem',
            'permissions' => [
                'triagem.visualizar' => 'Visualizar triagens',
                'triagem.registrar' => 'Registrar triagem e sinais vitais',
            ],
        ],

        'prontuario' => [
            'label' => 'Prontuário eletrônico',
            'permissions' => [
                'prontuario.visualizar' => 'Visualizar prontuário',
                'prontuario.editar' => 'Registrar e editar prontuário',
                'prontuario.finalizar' => 'Finalizar atendimento',
            ],
        ],

        'receita' => [
            'label' => 'Receitas',
            'permissions' => [
                'receita.emitir' => 'Emitir receitas',
                'receita.imprimir' => 'Imprimir/reimprimir receitas',
                'receita.cancelar' => 'Cancelar receitas emitidas',
            ],
        ],

        'atestado' => [
            'label' => 'Atestados',
            'permissions' => [
                'atestado.emitir' => 'Emitir atestados',
                'atestado.imprimir' => 'Imprimir/reimprimir atestados',
                'atestado.cancelar' => 'Cancelar atestados emitidos',
            ],
        ],

        'documento' => [
            'label' => 'Documentos e exames',
            'permissions' => [
                'documento.visualizar' => 'Visualizar documentos do paciente',
                'documento.anexar' => 'Anexar documentos e exames',
                'exame.solicitar' => 'Solicitar exames',
            ],
        ],

        'cadastro_clinico' => [
            'label' => 'Bases clínicas (CID / medicamentos)',
            'permissions' => [
                'medicamento.gerenciar' => 'Gerenciar base de medicamentos',
                'cid.gerenciar' => 'Gerenciar/importar base CID',
            ],
        ],

        'convenio' => [
            'label' => 'Convênios',
            'permissions' => [
                'convenio.visualizar' => 'Visualizar convênios',
                'convenio.gerenciar' => 'Gerenciar convênios, planos e tabelas',
                'convenio.autorizar' => 'Registrar autorizações',
            ],
        ],

        'financeiro' => [
            'label' => 'Financeiro',
            'permissions' => [
                'financeiro.visualizar' => 'Visualizar financeiro',
                'financeiro.editar' => 'Lançar e editar contas a pagar/receber',
                'financeiro.repasse' => 'Gerenciar repasses e split',
                'financeiro.conciliar' => 'Conciliação bancária',
                'financeiro.fechamento' => 'Fechamento mensal médico x clínica',
            ],
        ],

        'caixa' => [
            'label' => 'Caixa',
            'permissions' => [
                'caixa.operar' => 'Abrir, movimentar e fechar caixa',
                'caixa.conferir' => 'Conferir caixa e aprovar divergências',
            ],
        ],

        'pagamento' => [
            'label' => 'Pagamentos',
            'permissions' => [
                'pagamento.visualizar' => 'Visualizar pagamentos',
                'pagamento.cobrar' => 'Gerar cobranças e links de pagamento',
                'pagamento.estornar' => 'Solicitar estornos',
            ],
        ],

        'relatorio' => [
            'label' => 'Relatórios',
            'permissions' => [
                'relatorio.operacional' => 'Relatórios operacionais',
                'relatorio.clinico' => 'Relatórios clínicos',
                'relatorio.financeiro' => 'Relatórios financeiros',
            ],
        ],

        'integracao' => [
            'label' => 'Integrações',
            'permissions' => [
                'integracao.gerenciar' => 'Configurar integrações (pagamentos, WhatsApp, IA)',
                'webhook.gerenciar' => 'Gerenciar webhooks de saída',
            ],
        ],

        'ia' => [
            'label' => 'Inteligência artificial',
            'permissions' => [
                'ia.conversas' => 'Visualizar conversas da IA e assumir atendimento (handoff)',
                'ia.configurar' => 'Configurar personalidade e regras da IA',
            ],
        ],

        'impressao' => [
            'label' => 'Impressão',
            'permissions' => [
                'impressao.configurar' => 'Configurar modelos e impressoras',
            ],
        ],

        'platform' => [
            'label' => 'Plataforma SaaS (Super Admin)',
            'scope' => 'platform',
            'permissions' => [
                'platform.empresas' => 'Gerenciar empresas clientes',
                'platform.planos' => 'Gerenciar planos SaaS',
                'platform.saude' => 'Monitorar saúde do sistema',
                'platform.auditoria' => 'Auditoria técnica global',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Perfis padrão (templates)
    |--------------------------------------------------------------------------
    |
    | Copiados para cada nova empresa no provisionamento. A clínica pode
    | ajustar os perfis não bloqueados. '*' = todas as permissões de tenant.
    |
    */

    'role_templates' => [
        'admin_empresa' => [
            'name' => 'Administrador da empresa',
            'description' => 'Controle total da clínica e de todas as filiais.',
            'locked' => true,
            'permissions' => ['*'],
        ],
        'admin_filial' => [
            'name' => 'Administrador da filial',
            'description' => 'Controle da filial à qual está vinculado.',
            'locked' => false,
            'permissions' => [
                'dashboard.visualizar', 'dashboard.financeiro',
                'filial.visualizar', 'filial.editar',
                'usuario.visualizar', 'usuario.criar', 'usuario.editar', 'usuario.bloquear', 'usuario.perfis',
                'perfil.visualizar', 'auditoria.visualizar',
                'paciente.visualizar', 'paciente.criar', 'paciente.editar',
                'medico.visualizar', 'agenda.visualizar', 'agenda.criar', 'agenda.editar', 'agenda.cancelar',
                'agenda.encaixe', 'agenda.configurar', 'fila.visualizar', 'fila.gerenciar', 'fila.painel',
                'convenio.visualizar', 'financeiro.visualizar', 'caixa.operar', 'caixa.conferir',
                'pagamento.visualizar', 'pagamento.cobrar', 'convenio.autorizar', 'documento.anexar',
                'receita.imprimir', 'atestado.imprimir', 'ia.conversas',
                'relatorio.operacional', 'relatorio.financeiro', 'impressao.configurar',
            ],
        ],
        'medico' => [
            'name' => 'Médico',
            'description' => 'Atendimento clínico, prontuário e documentos médicos.',
            'locked' => false,
            'permissions' => [
                'dashboard.visualizar', 'paciente.visualizar', 'agenda.visualizar', 'fila.visualizar',
                'triagem.visualizar', 'prontuario.visualizar', 'prontuario.editar', 'prontuario.finalizar',
                'receita.emitir', 'receita.imprimir', 'receita.cancelar',
                'atestado.emitir', 'atestado.imprimir', 'atestado.cancelar',
                'documento.visualizar', 'documento.anexar', 'exame.solicitar',
                'relatorio.clinico',
            ],
        ],
        'recepcao' => [
            'name' => 'Recepção',
            'description' => 'Cadastro, agendamento, chegada, senhas e caixa.',
            'locked' => false,
            'permissions' => [
                'dashboard.visualizar', 'paciente.visualizar', 'paciente.criar', 'paciente.editar',
                'medico.visualizar', 'agenda.visualizar', 'agenda.criar', 'agenda.editar', 'agenda.cancelar',
                'agenda.encaixe', 'fila.visualizar', 'fila.gerenciar', 'convenio.visualizar',
                'convenio.autorizar', 'caixa.operar', 'pagamento.visualizar', 'pagamento.cobrar',
                'documento.anexar', 'receita.imprimir', 'atestado.imprimir', 'ia.conversas',
            ],
        ],
        'financeiro' => [
            'name' => 'Financeiro',
            'description' => 'Contas, recebimentos, caixa, conciliação e repasses.',
            'locked' => false,
            'permissions' => [
                'dashboard.visualizar', 'dashboard.financeiro', 'financeiro.visualizar', 'financeiro.editar',
                'financeiro.repasse', 'financeiro.conciliar', 'financeiro.fechamento',
                'caixa.operar', 'caixa.conferir', 'pagamento.visualizar', 'pagamento.cobrar',
                'pagamento.estornar', 'convenio.visualizar', 'relatorio.financeiro',
            ],
        ],
        'enfermagem' => [
            'name' => 'Enfermagem / Triagem',
            'description' => 'Triagem, sinais vitais e encaminhamento.',
            'locked' => false,
            'permissions' => [
                'dashboard.visualizar', 'paciente.visualizar', 'agenda.visualizar', 'fila.visualizar',
                'fila.gerenciar', 'triagem.visualizar', 'triagem.registrar', 'prontuario.visualizar',
            ],
        ],
    ],
];
