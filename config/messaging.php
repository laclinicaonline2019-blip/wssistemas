<?php

/*
| Mensagens automáticas ao paciente (Fase 11).
|
| WhatsApp oficial (Cloud API): mensagens iniciadas pela clínica só podem usar
| MODELOS APROVADOS pela Meta. Cadastre na Meta (categoria "Utilidade", idioma
| pt_BR) modelos com o MESMO texto e a MESMA ordem de variáveis abaixo — {{1}},
| {{2}}… — e informe o nome aprovado na tela "WhatsApp e notificações".
| O texto daqui também é usado no e-mail e no modo MOCK.
|
| Botões de resposta rápida (quick reply) devem ser criados no modelo na mesma
| ordem de "buttons"; o sistema envia o "payload" de cada botão.
*/

return [
    'purposes' => [
        'booking_confirmation' => [
            'label' => 'Agendamento realizado',
            'template' => 'aivexa_agendamento',
            'params' => ['nome', 'data', 'hora', 'medico', 'unidade', 'protocolo'],
            'text' => 'Olá, {nome}! Sua consulta foi agendada para {data} às {hora} com {medico} ({unidade}). Protocolo {protocolo}.',
        ],
        'reminder' => [
            'label' => 'Lembrete de consulta',
            'template' => 'aivexa_lembrete',
            'params' => ['nome', 'data', 'hora', 'medico', 'unidade'],
            'text' => 'Olá, {nome}! Lembrete da sua consulta em {data} às {hora} com {medico} ({unidade}). Responda: 1 para CONFIRMAR, 2 para CANCELAR ou 3 para REMARCAR.',
            'buttons' => ['CONFIRM' => 'Confirmar', 'CANCEL' => 'Cancelar', 'RESCHEDULE' => 'Remarcar'],
        ],
        'cancellation' => [
            'label' => 'Consulta cancelada',
            'template' => 'aivexa_cancelamento',
            'params' => ['nome', 'data', 'hora', 'medico'],
            'text' => 'Olá, {nome}. Sua consulta de {data} às {hora} com {medico} foi cancelada. Se quiser remarcar, é só responder esta mensagem.',
        ],
        'reschedule' => [
            'label' => 'Consulta remarcada',
            'template' => 'aivexa_remarcacao',
            'params' => ['nome', 'data', 'hora', 'medico', 'unidade'],
            'text' => 'Olá, {nome}! Sua consulta foi remarcada para {data} às {hora} com {medico} ({unidade}).',
        ],
        'no_show' => [
            'label' => 'Falta (ausência)',
            'template' => 'aivexa_ausencia',
            'params' => ['nome', 'data', 'medico'],
            'text' => 'Olá, {nome}. Sentimos sua falta na consulta de {data} com {medico}. Deseja remarcar? Responda esta mensagem.',
        ],
        'portal_access' => [
            'label' => 'Link do portal do paciente',
            'template' => 'aivexa_portal',
            'params' => ['nome', 'clinica', 'link'],
            'text' => 'Olá, {nome}! Este é o seu link para acessar o portal do paciente da {clinica}: {link} (uso único, não compartilhe).',
        ],
    ],

    // Respostas de texto aceitas ao lembrete (além dos botões).
    'keywords' => [
        'CONFIRM' => ['1', 'sim', 'confirmo', 'confirmar', 'confirmado', 'ok'],
        'CANCEL' => ['2', 'cancelar', 'cancela', 'cancelo', 'não vou', 'nao vou'],
        'RESCHEDULE' => ['3', 'remarcar', 'reagendar', 'remarca'],
    ],

    'max_attempts' => 5,
];
