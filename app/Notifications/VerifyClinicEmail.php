<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyClinicEmail extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('clinic.email.verify', now()->addMinutes(60), [
            'id' => $notifiable->id, 'hash' => sha1($notifiable->email),
        ]);

        return (new MailMessage)->subject('Verifique su correo en Clínica Vida Salud')
            ->greeting('Clínica Vida Salud')->line('Confirme este correo para utilizar su cuenta y recuperar su contraseña.')
            ->action('Verificar correo', $url)->line('El enlace vence en 60 minutos. Si no solicitó esta cuenta, ignore este mensaje.');
    }
}
