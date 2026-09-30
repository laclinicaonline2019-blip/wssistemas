<?php

/*
| Finalidades que dependem de CONSENTIMENTO do paciente (LGPD art. 7º I / 11 I).
| O atendimento em si (tutela da saúde, art. 11 II "f") e obrigações legais
| (prontuário) NÃO dependem de consentimento e não aparecem aqui.
| Ao mudar o texto de um termo, incremente a versão: consentimentos anteriores
| continuam registrados com a versão que o paciente aceitou.
*/

return [
    'purposes' => [
        'whatsapp_comunicacoes' => ['label' => 'Receber lembretes, confirmações e documentos pelo WhatsApp', 'version' => '1.0'],
        'email_comunicacoes' => ['label' => 'Receber comunicações e documentos por e-mail', 'version' => '1.0'],
        'atendimento_ia' => ['label' => 'Ser atendido por assistente virtual (IA) para agendamentos', 'version' => '1.0'],
        'telemedicina' => ['label' => 'Atendimento por telemedicina', 'version' => '1.0'],
        'marketing' => ['label' => 'Receber campanhas, novidades e promoções da clínica', 'version' => '1.0'],
        'pesquisa_anonimizada' => ['label' => 'Uso de dados anonimizados em estudos e indicadores', 'version' => '1.0'],
    ],

    'channels' => ['presencial' => 'Presencial', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal do paciente', 'email' => 'E-mail', 'telefone' => 'Telefone'],
];
