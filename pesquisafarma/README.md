<p align="center"><img src="public/img/logo-640.webp" width="320" alt="Pesquisa Farma"></p>

# Pesquisa Farma — marketplace de cotação de medicamentos

O cliente manda a lista ou a foto da receita pelo **WhatsApp**. A Pesquisa Farma envia a cotação para as
**farmácias mais próximas do bairro dele**. As farmácias respondem os preços (ético, genérico e similar,
taxa de entrega e promoções) e o sistema monta o **ranking do melhor preço**. A venda fica registrada
para a **comissão de cada farmácia**, e o cliente acumula **pontos** ao enviar a nota fiscal.

**Stack:** PHP 8.3+ · Laravel 13 · MySQL/MariaDB (ou SQLite/PostgreSQL) · Blade + CSS/JS próprios (sem build) · Claude (SDK oficial da Anthropic)

## Como funciona

```
Cliente (WhatsApp) ──► Robô: 1º contato = cadastro (nome, CEP/bairro, endereço, LGPD, aceite de promoções)
        │                    └─ a lista/foto enviada antes do cadastro é guardada e vira cotação no fim
        ▼
   Cotação recebida ──► IA separa os itens (texto, foto da receita ou da caixa) ──► televendas confere
        ▼
   Envio às farmácias próximas (raio/bairro/zona de entrega) + opção "ampliada" (mais distantes)
        │   cada farmácia recebe no WhatsApp um link para responder (sem login) ou usa o painel
        ▼
   Respostas: preço por tipo (ético/referência, genérico, similar), entrega (taxa, prazo), sugestões/promoções
        ▼
   Ranking: mais itens → (se quer entrega) quem entrega → menor total com entrega; "cesta ideal" por item
        ▼
   Resultado no WhatsApp (top 1/2/3 com bairro, distância, entrega e endereço) ──► cliente responde "1", "2"...
        ▼
   Pedido gerado e enviado à farmácia e ao cliente
        ▼
   Pós-venda automático (prazo de entrega + 30 min): UMA mensagem com 3 perguntas
     1️⃣ Seu pedido chegou? S/N   2️⃣ De 0 a 10, indicaria a Pesquisa Farma?   3️⃣ Foto da nota → brindes
     cliente responde "S 10" + foto · "N" avisa a farmácia e o televendas e pergunta de novo em 1 h
     cotação sem resposta → "número da opção, COMPREI ou DESISTI" (COMPREI → indicação + nota)
        ▼
   Foto da nota (sem precisar escrever NOTA) ──► IA lê CNPJ/itens/total ──► liga ao pedido ──► conferência
        ├─ comissão passa a incidir sobre TUDO o que foi comprado (total da nota)
        ├─ comparativo cotado × comprado (itens fora da cotação)
        └─ pontos de fidelidade + participação na premiação do mês
```

## Módulos

| Área | O que tem |
|---|---|
| **Conversas** | Caixa de entrada compartilhada por todos os televendas, envio manual, "→ Cotação" e "→ Nota fiscal" a partir de qualquer mensagem |
| **Cotações** | Fila por prioridade, itens editáveis, preferência por item, leitura por IA, seleção de farmácias por distância, retorno das farmácias em tempo real, registro de resposta por telefone, ranking, cesta ideal, envio ao cliente, pedido, perda com motivo, avaliação e histórico |
| **Cidades e bairros** | Vários municípios e estados: cidades com UF e bairros dentro de cada cidade (com coordenadas), importação por CSV que cria as cidades, filtros por UF/cidade em farmácias, clientes e relatórios, relatório **Vendas por cidade/UF**, divulgação de promoção por cidade |
| **Farmácias** | Cadastro por estado → cidade → bairro, com **% de comissão e ciclo (semanal/mensal)**, CNPJ (reconhece a nota), coordenadas, regras de entrega (raio, taxa base + por km, grátis acima de, prazo) e **zonas de entrega por bairro**; acessos ao painel |
| **Painel da farmácia** | Cotações aguardando, resposta com ético/genérico/similar e sugestões, pedidos, vendas e comissão, fechamentos, **promoções do dia** |
| **Promoções** | Aprovação e **divulgação no WhatsApp** para a lista (toda a lista, mesmo bairro ou clientes próximos), só para quem aceitou; "SAIR" descadastra |
| **Pós-venda** | Pesquisa única no WhatsApp (entrega S/N + indicação 0–10 + foto da nota), entregas com problema no painel com aviso à farmácia, NPS por televendas e no mês, % de pedidos com nota fiscal, clientes que compraram sem pedido |
| **Incentivo para cotar com a gente** | Compra pela cotação: pontos em dobro e premiação do mês. Compra direta no balcão sem cotação: metade dos pontos e fora da premiação (a comissão é cobrada igual). Multiplicadores em Configurações. Indicador "Compras pela cotação" no painel |
| **Compra no balcão com o cartão** | Cliente compra direto na farmácia credenciada, sem cotação: mostra o cartão e manda a foto da nota (a IA identifica se a foto é nota ou receita; sem IA o robô pergunta 1/2). A farmácia também pode conferir o cartão e registrar a venda no painel. Gera pontos e comissão; nota repetida é bloqueada; "FARMÁCIAS" no WhatsApp lista as credenciadas mais próximas |
| **Notas fiscais** | Captação da venda pela foto da nota (IA), vínculo automático ao pedido, conferência, notas avulsas (sem pedido) também geram comissão |
| **Comissões** | Fechamento por farmácia (semanal/mensal), automático todo dia às 06:10 para o período anterior; emitido/pago/cancelado |
| **Fidelidade** | Regras de pontos (por R$, bônus por produto, multiplicador por farmácia), brindes com estoque e nº mínimo de compras, trocas, **premiação mensal** (sorteio por nota ou ranking) |
| **Cartão fidelidade** | Na área do cliente, na conversa e na ficha: saldo, nº do cartão, barra até o próximo brinde e brindes liberados. O cliente escreve **PONTOS** no WhatsApp e o robô responde na hora; o televendas envia o saldo com um clique |
| **Área do cliente** (`/cliente`) | Login por código no WhatsApp, cartão fidelidade, pontos, brindes, envio de nota, promoções, histórico e avaliação; liga/desliga nas configurações |
| **Relatórios** | Vendas **por farmácia (empresa)**, compras **por cliente**, cotado × comprado, **televendas** (atendimentos, conversão, tempos, avaliação, ações e mensagens), **tempos** (pedido→envio, envio→1ª resposta, resposta de cada farmácia, pedido→finalização, notas de 1 a 5), extrato detalhado; exportação Excel (CSV) e impressão |
| **Atividades** | Tudo o que cada televendas, farmácia e admin faz fica registrado (quem, o quê, quando, IP) |

## Perfis

- **Administrador**: tudo, incluindo cadastros (farmácias, bairros, usuários) e configurações.
- **Televendas**: atendimento, cotações, pedidos, notas, clientes, promoções, fidelidade, comissões e relatórios.
- **Farmácia**: apenas o próprio painel (cotações, pedidos, vendas/comissão, promoções).

## Instalação

```bash
cd pesquisafarma
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan pf:admin voce@empresa.com.br 'SenhaForte@2026' --nome="Seu Nome"
php artisan db:seed --class=DemoSeeder   # opcional: dados FICTÍCIOS de demonstração
php artisan serve
```

Demonstração: `admin@pesquisafarma.local` / `Trocar@123`, `televendas1@demo.local` / `Demo@12345`,
`farmacia@demo.local` / `Demo@12345`.

**Agendador (obrigatório em produção)** — no cron: `* * * * * php /caminho/pesquisafarma/artisan schedule:run`
(envia o pós-venda, expira respostas atrasadas, gera fechamentos de comissão e encerra cotações paradas há 3 dias).

### WhatsApp

Em `.env`, `WHATSAPP_DRIVER` = `mock` (demonstração), `zapi`, `evolution` ou `meta` (oficial), com as
credenciais do provedor. Configure no provedor o webhook
`https://SEU-DOMINIO/webhook/whatsapp/{WHATSAPP_WEBHOOK_SECRET}` (aparece em Configurações).
Na Evolution API, ative o envio de mídia em base64 no webhook.

### IA (Claude)

Defina `ANTHROPIC_API_KEY` (modelo padrão `claude-opus-5-5`, com esforço baixo para responder rápido e
saída em JSON validado por schema). A IA lê o pedido do cliente (texto/foto) e as notas fiscais. Ela só
transcreve: o televendas confere tudo. Sem chave, o sistema separa os itens do texto por regras simples e
a nota é digitada na conferência.

### Bairros e distância

Cada bairro pertence a uma cidade (com UF) e tem um ponto central (latitude/longitude); importe por CSV em **Cidades e bairros**
(`bairro;cidade;uf;latitude;longitude`). A farmácia pode ter coordenadas exatas. A busca "mais próximas"
usa o raio curto (padrão 3 km), o mesmo bairro e as zonas de entrega. A "ampliada" vai até o raio longo
(padrão 15 km), para mostrar ao cliente se vale a pena ir mais longe. No primeiro contato, o CEP é
consultado no ViaCEP para identificar bairro, cidade e estado. Sem CEP, o cliente informa "bairro, cidade";
se o bairro existir em mais de uma cidade, o robô pergunta a cidade. Sem coordenadas, uma cotação nunca
mistura farmácias de outra cidade.

## Produção

Roteiro completo (servidor, WhatsApp e IA reais, homologação com 1–2 televendas e 1 farmácia, abertura):
**[docs/PRODUCAO.md](docs/PRODUCAO.md)**. Conferência automática: `php artisan pf:verificar --testar-ia` e
`php artisan pf:testar-whatsapp <celular>`.

## Testes

```bash
php artisan test
```

Os testes cobrem o fluxo inteiro, do primeiro "oi" no WhatsApp até o fechamento de comissão e os pontos:
cadastro pelo robô, cotação, envio por distância, respostas das farmácias, ranking com entrega, escolha
pelo WhatsApp, pedido, avaliação, nota fiscal (comissão sobre o total comprado), troca de brindes,
divulgação de promoções, área do cliente, permissões por perfil e abertura de todas as telas.
