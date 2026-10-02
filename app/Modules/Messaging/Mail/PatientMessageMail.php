<?php

namespace App\Modules\Messaging\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Lembretes/avisos ao paciente por e-mail (mesmo texto do WhatsApp). */
class PatientMessageMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $clinicName, public readonly string $subjectLine, public readonly string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->clinicName}: {$this->subjectLine}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.patient-message');
    }
}
