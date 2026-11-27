<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\PasswordRecoveryLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SendPasswordRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $email) {}

    public function handle(): void
    {
        $origin = config()->string('app.url');
        if (! str_starts_with($origin, 'https://') || ! filter_var($origin, FILTER_VALIDATE_URL)) {
            Log::warning('Password recovery requires a valid HTTPS application URL.');

            return;
        }

        try {
            DB::transaction(function () use ($origin): void {
                $user = User::query()->where('email', $this->email)->lockForUpdate()->first();
                if ($user === null || $user->disabled_at !== null) {
                    return;
                }

                Password::broker()->sendResetLink(['email' => $user->email], function (User $recipient, #[\SensitiveParameter] string $token) use ($origin): void {
                    $recipient->notify((new PasswordRecoveryLink($origin, $token))->locale($recipient->locale));
                });
            });
        } catch (TransportExceptionInterface) {
            Log::warning('Password recovery mail delivery failed.');
        }
    }
}
