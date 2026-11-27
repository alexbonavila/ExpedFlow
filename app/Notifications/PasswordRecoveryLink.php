<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordRecoveryLink extends Notification
{
    public function __construct(
        private readonly string $origin,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $query = http_build_query(['token' => $this->token, 'email' => $notifiable->email], encoding_type: PHP_QUERY_RFC3986);
        $url = rtrim($this->origin, '/').'/reset-password#'.$query;

        return (new MailMessage)
            ->subject(__('identity.recovery_subject'))
            ->line(__('identity.recovery_intro'))
            ->action(__('identity.recovery_action'), $url)
            ->line(__('identity.recovery_expiry', ['minutes' => config('auth.passwords.users.expire')]))
            ->line(__('identity.recovery_ignore'));
    }
}
