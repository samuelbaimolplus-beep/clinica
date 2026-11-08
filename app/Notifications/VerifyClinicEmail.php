<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyClinicEmail extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Verifique su correo en Clínica Vida Salud')
            ->greeting('Clínica Vida Salud')->line('La clínica ha registrado su cuenta. Para activarla, ingrese este código en la pantalla «Activar mi cuenta»:')
            ->line($this->code)->action('Activar mi cuenta', url('/').'#activar-cuenta')
            ->line('El código vence en 15 minutos y solo puede utilizarse una vez. No lo comparta con otras personas.');
    }
}
