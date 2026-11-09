<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\AiDrafting;
use App\Integrations\Contracts\CourtDataProvider;
use App\Integrations\Contracts\ESignProvider;
use App\Integrations\Contracts\MessageSender;
use App\Integrations\Contracts\PaymentGateway;
use App\Integrations\Contracts\VideoProvider;
use App\Integrations\IntegrationNotConfigured;
use App\Models\Invoice;
use App\Models\OdrSession;
use App\Models\OdrSettlement;
use App\Models\User;

/**
 * The default for every outside service. It never makes a network call and always refuses,
 * so nothing leaves the platform until credentials are added and approved.
 */
class NotConfiguredDriver implements AiDrafting, CourtDataProvider, ESignProvider, MessageSender, PaymentGateway, VideoProvider
{
    public function __construct(private string $name) {}

    public function createPaymentLink(Invoice $invoice): array
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function createMeeting(OdrSession $session): string
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function requestSignature(OdrSettlement $settlement, User $signer): string
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function caseStatus(string $cnr): array
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function draft(string $documentType, array $facts): string
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function summarise(string $text): string
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function push(string $deviceToken, string $title, string $body): void
    {
        throw new IntegrationNotConfigured($this->name);
    }

    public function sms(string $phone, string $text): void
    {
        throw new IntegrationNotConfigured($this->name);
    }
}
