<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitation extends Notification
{
    public function __construct(private string $restaurant, private string $token, private int $days) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // shortcut: the link points at this API's host until the Vue shell has its own route; change with the frontend in 3d.
        $url = config('app.url').'/accept-invitation?'.http_build_query(['token' => $this->token]);

        return (new MailMessage)
            ->subject("Invitación a {$this->restaurant}")
            ->line("Te han invitado a trabajar en {$this->restaurant} en Mestaro.")
            ->action('Aceptar invitación', $url)
            ->line("El enlace caduca en {$this->days} días. Si no lo esperabas, ignora este correo: no se añade nada a tu cuenta.");
    }
}
