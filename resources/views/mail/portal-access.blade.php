<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>{{ $clinicName }}</title></head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
<p>Olá, {{ $patientFirstName }}.</p>
@if ($purpose === 'activation')
    <p>A {{ $clinicName }} liberou o seu acesso ao <strong>portal do paciente</strong>, onde você acompanha consultas, documentos e pagamentos.</p>
    <p><a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:#1d6ff2;color:#fff;text-decoration:none;border-radius:6px;">Criar minha senha</a></p>
@else
    <p>Recebemos um pedido para redefinir a sua senha do portal do paciente da {{ $clinicName }}.</p>
    <p><a href="{{ $url }}" style="display:inline-block;padding:10px 18px;background:#1d6ff2;color:#fff;text-decoration:none;border-radius:6px;">Redefinir senha</a></p>
@endif
<p style="font-size: 13px; color: #6b7280;">O link vale por {{ $expiresIn }} e só pode ser usado uma vez. Se você não pediu, ignore este e-mail — sua conta continua segura.</p>
</body></html>
