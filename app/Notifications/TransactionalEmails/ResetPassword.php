<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\InstanceSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPassword extends Notification
{
    public static $createUrlCallback;

    public static $toMailCallback;

    public $token;

    public InstanceSettings $settings;

    public function __construct($token, public bool $isTransactionalEmail = true)
    {
        $this->settings = instanceSettings();
        $this->token = $token;
    }

    public static function createUrlUsing($callback)
    {
        static::$createUrlCallback = $callback;
    }

    public static function toMailUsing($callback)
    {
        static::$toMailCallback = $callback;
    }

    public function via($notifiable)
    {
        return [];
    }

    public function toMail($notifiable)
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        return $this->buildMailMessage($this->resetUrl($notifiable));
    }

    protected function buildMailMessage($url)
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Password Recovery Disabled');
        $mail->line('Password recovery is disabled. Use SL1 Identity.');
        $mail->action('Back to Login', $url);

        return $mail;
    }

    protected function resetUrl($notifiable)
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        $path = route('login', [], false);

        return rtrim(base_url(), '/').$path;
    }
}
