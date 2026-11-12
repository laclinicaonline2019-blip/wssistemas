<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject('Redefinição de senha — '.config('wsf.brand.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para redefinir a sua senha.')
            ->action('Criar nova senha', $url)
            ->line('O link vale por 60 minutos. Se não foi você, ignore este e-mail — sua senha continua a mesma.')
            ->salutation('Equipe '.config('wsf.brand.name'));
    }
}
