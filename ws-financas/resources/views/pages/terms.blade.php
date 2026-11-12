@extends('layouts.guest')
@section('title', 'Termos de uso')
@section('content')
<article class="card prose" style="max-width:none">
  <h2>Termos de uso</h2>
  <p class="small muted">Modelo de referência — revise com sua assessoria jurídica antes da publicação.</p>
  <h3>1. Serviço</h3><p>O WS Finanças em Dia é uma plataforma de organização financeira por assinatura. As sugestões e análises são educativas e não substituem orientação contábil, jurídica ou de investimentos.</p>
  <h3>2. Teste e assinatura</h3><p>O teste grátis dura {{ config('wsf.billing.trial_days') }} dias. A assinatura é mensal ou anual, cobrada por Pix, boleto ou cartão via Asaas, com renovação automática até o cancelamento.</p>
  <h3>3. Inadimplência</h3><p>Com cobrança vencida há mais de {{ config('wsf.billing.grace_days') }} dias a conta é suspensa: os dados continuam disponíveis para consulta e exportação, mas novos lançamentos ficam bloqueados até a regularização.</p>
  <h3>4. Cancelamento</h3><p>Pode ser feito a qualquer momento no painel e vale ao fim do período pago. Os dados ficam disponíveis por {{ config('wsf.lgpd.retention_days_after_cancel') }} dias e depois são eliminados.</p>
  <h3>5. Responsabilidades do cliente</h3><p>Manter senhas em sigilo, autorizar apenas números de WhatsApp de pessoas de confiança e conferir os lançamentos registrados pelo assistente.</p>
  <h3>6. WhatsApp</h3><p>O assistente usa a API oficial do WhatsApp Business e segue as políticas da Meta. Avisos iniciados pela plataforma só são enviados a quem aceitou recebê-los.</p>
</article>
@endsection
