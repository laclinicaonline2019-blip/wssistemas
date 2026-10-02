Olá, {{ $patientFirstName }}.

@if ($purpose === 'activation')
A {{ $clinicName }} liberou o seu acesso ao portal do paciente. Crie sua senha em:
@else
Para redefinir a sua senha do portal do paciente da {{ $clinicName }}, acesse:
@endif
{{ $url }}

O link vale por {{ $expiresIn }} e só pode ser usado uma vez. Se você não pediu, ignore esta mensagem.
