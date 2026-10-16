<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Companies\Models\Company;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyOwnershipTransferNotification extends Notification
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
        $transferUrl = url("/app/{$this->company->slug}/ownership-transfers/{$this->plainToken}");

        return (new MailMessage)
            ->subject("Solicitud de Transferencia de Titularidad - {$this->company->legal_name}")
            ->greeting('Estimado(a) miembro,')
            ->line("El propietario actual de **{$this->company->legal_name}** ha iniciado una solicitud para transferirle formalmente la titularidad exclusiva de la empresa.")
            ->line('Para aceptar esta designación debe acceder con su cuenta activa, tener su autenticación de dos factores (2FA) configurada y confirmar la transferencia.')
            ->line("Esta solicitud es de un solo uso y vencerá el {$this->expiresAt->format('d/m/Y H:i')}.")
            ->action('Revisar Transferencia de Titularidad', $transferUrl)
            ->line('Si no esperaba esta solicitud o no desea asumir la titularidad, puede comunicarse con el remitente o ignorar este mensaje.');
    }
}
