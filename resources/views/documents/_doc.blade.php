@php
    $c = $doc->content;
    $clinic = $c['clinic']; $dr = $c['doctor']; $pt = $c['patient'];
    $tz = $doc->branch?->timezone ?: 'America/Sao_Paulo';
    $issued = $doc->issued_at->timezone($tz);
    $url = $service->validationUrl($doc);
    $titles = [
        'prescription' => 'Receituário', 'special_prescription' => 'Receituário de controle especial',
        'notification_record' => 'Registro interno — notificação de receita', 'certificate' => $doc->subtype === 'attendance' ? 'Declaração de comparecimento' : 'Atestado médico',
        'exam_request' => 'Solicitação de exames', 'report' => $c['title'] ?? 'Documento médico',
    ];
    $thermal = $format === 'thermal';
@endphp
<div class="doc">
    @if ($doc->isCancelled())
        <div class="stamp-cancelled">DOCUMENTO CANCELADO em {{ $doc->cancelled_at->timezone($tz)->format('d/m/Y H:i') }} — sem validade</div>
    @endif

    <table class="hdr"><tr>
        <td>
            <div class="clinic">{{ $clinic['name'] }}</div>
            <div class="muted">{{ $clinic['address'] }}{{ $clinic['zip_code'] ? ' · CEP '.$clinic['zip_code'] : '' }}</div>
            <div class="muted">{{ collect([$clinic['phone'] ? 'Tel. '.$clinic['phone'] : null, $clinic['document'] ? 'CNPJ '.$clinic['document'] : null])->filter()->implode(' · ') }}</div>
        </td>
        <td class="r">
            <div class="doctor-name">{{ $dr['name'] }}</div>
            <div class="muted">{{ $dr['registration'] }}</div>
            @if ($dr['specialty'])<div class="muted">{{ $dr['specialty'] }}{{ $dr['rqe'] ? ' · RQE '.$dr['rqe'] : '' }}</div>@endif
        </td>
    </tr></table>

    <div class="title">{{ $titles[$doc->type] }}</div>
    @if ($via)<div class="via">{{ $via }}</div>@endif

    @if ($doc->type === 'special_prescription')
        <div class="box">
            <div class="box-title">Identificação do emitente</div>
            <table class="kv">
                <tr><td class="k">Nome</td><td>{{ $dr['name'] }} — {{ $dr['registration'] }}</td></tr>
                <tr><td class="k">Endereço</td><td>{{ $clinic['address'] }}</td></tr>
                <tr><td class="k">Cidade/UF</td><td>{{ $clinic['city'] }}{{ $clinic['state'] ? '/'.$clinic['state'] : '' }}{{ $clinic['phone'] ? ' · Tel. '.$clinic['phone'] : '' }}</td></tr>
            </table>
        </div>
    @endif

    <div class="box">
        <table class="kv">
            <tr><td class="k">Paciente</td><td><strong>{{ $pt['name'] }}</strong>{{ $pt['civil_name'] ? ' (registro civil: '.$pt['civil_name'].')' : '' }}</td></tr>
            @if ($pt['cpf'] || $pt['birth_date'])
                <tr><td class="k">{{ $pt['cpf'] ? 'CPF' : 'Nascimento' }}</td><td>{{ $pt['cpf'] }}{{ $pt['cpf'] && $pt['birth_date'] ? ' · Nasc. ' : '' }}{{ $pt['birth_date'] }}{{ $pt['age'] !== null ? ' ('.$pt['age'].' anos)' : '' }}</td></tr>
            @endif
            @if (in_array($doc->type, ['special_prescription', 'notification_record'], true))
                <tr><td class="k">Endereço</td><td>{{ $pt['address'] ?? '______________________________________________' }}</td></tr>
            @endif
        </table>
    </div>

    @switch($doc->type)
        @case('prescription')
        @case('special_prescription')
            @php $route = false; @endphp
            <table class="items">
                @foreach ($c['items'] as $i => $item)
                    @if (($item['route'] ?? null) && $item['route'] !== $route)
                        @php $route = $item['route']; @endphp
                        <tr><td colspan="3"><div class="route">USO {{ mb_strtoupper($item['route']) }}</div></td></tr>
                    @endif
                    <tr><td class="n">{{ $i + 1 }}.</td><td>{{ $item['name'] }}</td><td class="q">{{ $item['quantity'] }}</td></tr>
                    <tr><td></td><td colspan="2" class="posology">{{ $item['posology'] }}</td></tr>
                @endforeach
            </table>
            @if (! empty($c['notes']))<div class="notes"><strong>Orientações:</strong> {{ $c['notes'] }}</div>@endif
            @if ($doc->valid_until)<div class="muted">Validade da receita: até {{ $doc->valid_until->format('d/m/Y') }}.</div>@endif
            @break

        @case('notification_record')
            <div class="warning">Este registro NÃO autoriza a dispensação. Os medicamentos abaixo foram prescritos na Notificação de Receita oficial (talão da Vigilância Sanitária), preenchida e assinada à mão.</div>
            <table class="items">
                @foreach ($c['items'] as $i => $item)
                    <tr><td class="n">{{ $i + 1 }}.</td><td>{{ $item['name'] }} <span class="muted">(lista {{ $item['control_type'] }})</span></td><td class="q">Notificação nº {{ $item['notification_number'] }}</td></tr>
                    <tr><td></td><td colspan="2" class="posology">{{ $item['quantity'] }} — {{ $item['posology'] }}</td></tr>
                @endforeach
            </table>
            @break

        @case('certificate')
            <div class="text">{{ $c['text'] }}</div>
            @if (! empty($c['notes']))<div class="notes">{{ $c['notes'] }}</div>@endif
            @break

        @case('exam_request')
            @if (! empty($c['urgent']))<div class="warning">URGENTE</div>@endif
            <div><strong>Solicito:</strong></div>
            <ol class="exams">@foreach ($c['exams'] as $e)<li>{{ $e }}</li>@endforeach</ol>
            @if (! empty($c['indication']))<div class="notes"><strong>Indicação clínica:</strong> {{ $c['indication'] }}</div>@endif
            @if (! empty($c['cid']))<div class="notes"><strong>CID-10:</strong> {{ $c['cid']['code'] }} — {{ $c['cid']['description'] }}</div>@endif
            @break

        @case('report')
            @if (! empty($c['recipient']))<div class="notes"><strong>{{ $doc->subtype === 'referral' ? 'Encaminho a' : 'A/C' }}:</strong> {{ $c['recipient'] }}</div>@endif
            <div class="text">{{ $c['body'] }}</div>
            @break
    @endswitch

    <div class="place-date">{{ $clinic['city'] ? $clinic['city'].', ' : '' }}{{ $issued->translatedFormat('d \d\e F \d\e Y') }}</div>
    <div class="sign">
        <div class="sign-line"></div>
        <div><strong>{{ $dr['name'] }}</strong></div>
        <div class="muted">{{ $dr['registration'] }}{{ $dr['rqe'] ? ' · RQE '.$dr['rqe'] : '' }}</div>
    </div>

    @if ($doc->type === 'special_prescription')
        <table class="buyer"><tr>
            <td><div class="box-title">Identificação do comprador</div>Nome:<br><br>RG: ______________ Órgão emissor: ______<br>Endereço:<br><br>Cidade: ______________ UF: ___ Telefone: ____________</td>
            <td><div class="box-title">Identificação do fornecedor</div><br><br><br>Assinatura do farmacêutico<br><br>Data: ___/___/______</td>
        </tr></table>
    @endif

    <table class="ftr"><tr>
        <td class="qr"><img src="{{ $service->qrDataUri($url) }}" alt="QR Code de validação"></td>
        <td>
            {{ $doc->typeLabel() }} nº {{ $doc->displayNumber() }} · emitido em {{ $issued->format('d/m/Y H:i') }}<br>
            Código de verificação <strong>{{ $doc->formattedCode() }}</strong> — confira a autenticidade em {{ $url }}<br>
            Documento assinado de próprio punho.@if ($doc->print_count > 1) Reimpressão (via nº {{ $doc->print_count }}).@endif
        </td>
    </tr></table>
</div>
