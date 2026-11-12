@extends('layouts.guest')
@section('title', 'Política de privacidade')
@section('content')
<article class="card prose" style="max-width:none">
  <h2>Política de privacidade</h2>
  <p class="small muted">Modelo de referência — revise com sua assessoria jurídica antes da publicação. Controlador: {{ config('wsf.brand.company_legal') }} · Contato do encarregado (DPO): {{ config('wsf.brand.support_email') }}</p>
  <h3>1. Quais dados tratamos</h3>
  <p>Dados de cadastro (nome, CPF/CNPJ, e-mail, telefone), dados de acesso (registros de login, IP), dados financeiros que você lança (receitas, despesas, contas bancárias, dívidas) e mensagens enviadas ao assistente no WhatsApp pelos números que você autoriza.</p>
  <h3>2. Para que usamos</h3>
  <p>Prestar o serviço contratado (execução de contrato), cobrar a assinatura, cumprir obrigações legais e fiscais, manter a segurança (legítimo interesse) e enviar avisos que você autorizou. Não vendemos dados nem usamos seus lançamentos para publicidade.</p>
  <h3>3. Com quem compartilhamos</h3>
  <p>Somente operadores necessários ao serviço: hospedagem, Meta (WhatsApp Business), Asaas (cobrança) e, quando uma mensagem não é entendida pelas regras automáticas, o provedor de inteligência artificial (Anthropic), que recebe apenas o texto da mensagem e a lista de nomes das suas empresas.</p>
  <h3>4. Segurança</h3>
  <p>Senhas com hash forte, verificação em duas etapas, criptografia de documentos, telefones e mensagens, isolamento completo entre clientes, trilha de auditoria imutável e backups criptografados.</p>
  <h3>5. Retenção e exclusão</h3>
  <p>Enquanto a assinatura estiver ativa os dados são mantidos. Após o cancelamento ficam disponíveis para consulta e exportação por {{ config('wsf.lgpd.retention_days_after_cancel') }} dias e depois são eliminados, exceto o que a lei obriga a guardar (por exemplo, registros de cobrança). O texto das mensagens do WhatsApp é anonimizado após {{ config('wsf.lgpd.whatsapp_body_days') }} dias.</p>
  <h3>6. Seus direitos (LGPD, art. 18)</h3>
  <p>Confirmação, acesso, correção, portabilidade (exportação em Privacidade → Exportar meus dados), eliminação, informação sobre compartilhamento e revogação de consentimento. Solicite pelo e-mail {{ config('wsf.brand.support_email') }}.</p>
</article>
@endsection
