<?php

namespace App\Modules\Portal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Link de ativação / redefinição de senha do portal. Sem dados de saúde no e-mail. */
class PortalAccessMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $clinicName,
        public readonly string $patientFirstName,
        public readonly string $url,
        public readonly string $purpose,
        public readonly string $expiresIn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'activation'
            ? "{$this->clinicName}: ative seu acesso ao portal do paciente"
            : "{$this->clinicName}: redefinição de senha do portal do paciente");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-access', text: 'mail.portal-access-text');
    }
}
