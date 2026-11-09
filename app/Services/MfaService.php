<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords. Staff context requires this (D5.1). The secret
 * is stored with the encrypted cast on users.two_factor_secret.
 */
class MfaService
{
    public function __construct(private Google2FA $engine = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl((string) config('app.name'), (string) $user->email, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1 && $this->engine->verifyKey($secret, $code) !== false;
    }

    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }
}
