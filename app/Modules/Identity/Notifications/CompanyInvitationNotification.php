<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Companies\Models\Company;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Company $company,
        public string $plainToken,
        public DateTimeInterface|CarbonInterface $expiresAt
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $inviteUrl = url("/invitations/{$this->plainToken}");

        return (new MailMessage)
            ->subject("Invitación para unirte a {$this->company->legal_name}")
            ->greeting('¡Hola!')
            ->line("Has sido invitado a formar parte del equipo de **{$this->company->legal_name}**.")
            ->line("Este enlace es de uso único y estará disponible hasta el {$this->expiresAt->format('d/m/Y H:i')}.")
            ->action('Aceptar Invitación', $inviteUrl)
            ->line('Si no esperabas esta invitación o no reconoces la empresa, puedes ignorar este correo.');
    }
}
