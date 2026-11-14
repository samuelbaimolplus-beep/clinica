<?php

/** Componente VerifyClinicEmail: colabora con los módulos del sistema sin reemplazar sus controles del servidor. */

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificarCorreoClinica extends Notification
{
    /** Recibe las dependencias o datos que necesita esta instancia. */
    public function __construct(public readonly string $code) {}

    /** Declara los canales de entrega de esta notificación. */
    public function via(object $destinatario): array
    {
        return ['mail'];
    }

    /** Compone el correo de activación para su destinatario. */
    public function toMail(object $destinatario): MailMessage
    {
        return (new MailMessage)->subject('Verifique su correo en Clínica Vida Salud')
            ->greeting('Clínica Vida Salud')->line('La clínica ha registrado su cuenta. Para activarla, ingrese este código en la pantalla «Activar mi cuenta»:')
            ->line($this->code)->action('Activar mi cuenta', url('/').'#activar-cuenta')
            ->line('El código vence en 15 minutos y solo puede utilizarse una vez. No lo comparta con otras personas.');
    }
}
