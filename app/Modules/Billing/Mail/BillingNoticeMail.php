<?php

namespace App\Modules\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avisos da assinatura para a clínica (fatura, atraso, bloqueio, encerramento). */
class BillingNoticeMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $clinicName, public readonly string $subjectLine, public readonly string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "aivexaclinica: {$this->subjectLine}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.billing-notice');
    }
}
