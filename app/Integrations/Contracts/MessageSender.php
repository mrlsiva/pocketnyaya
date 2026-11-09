<?php

namespace App\Integrations\Contracts;

/** Step 22. Push notifications and SMS. Messages never contain case content. */
interface MessageSender
{
    public function push(string $deviceToken, string $title, string $body): void;

    public function sms(string $phone, string $text): void;
}
