<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use SensitiveParameter;

class IdentityLink extends Mailable
{
    public function __construct(public readonly string $kind, private string $email, #[SensitiveParameter] private string $token) {}

    public function build(): static
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $url = $base.'/'.($this->kind === 'activation' ? 'activate' : 'reset-password')
            .'#'.http_build_query(['email' => $this->email, 'token' => $this->token], encoding_type: PHP_QUERY_RFC3986);

        return $this->subject($this->kind === 'activation' ? 'VELTRA - verify your account' : 'VELTRA - reset your password')
            ->text('mail.identity-link', ['url' => $url, 'hours' => $this->kind === 'activation' ? '24 hours' : '30 minutes']);
    }
}
