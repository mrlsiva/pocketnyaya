<?php

namespace App\Integrations;

/** What each outside service is for, and whether a real driver is set. No secrets are exposed. */
class IntegrationRegistry
{
    /** @var array<string, array{0: string, 1: string}> */
    public const SERVICES = [
        'payments' => ['Payment gateway', 'Step 24. Payment links on invoices.'],
        'video' => ['Video calls', 'Step 23. Meeting links for mediation sessions.'],
        'esign' => ['Aadhaar eSign or DSC', 'Step 21. Signing settlement agreements.'],
        'court_data' => ['NJDG or eCourts', 'Step 20. Case status by CNR number.'],
        'ai' => ['AI drafting and summaries', 'Steps 17 and 18. Drafts for an advocate to check.'],
        'messaging' => ['Push and SMS', 'Step 22. Alerts without case content.'],
    ];

    /**
     * Real drivers, by service and driver name. Built on 2026-11-10 for the owner's chosen providers. Each stays switched off until
     * its name and credentials are set in .env; any other name falls back to switched off.
     *
     * @var array<string, array<string, class-string>>
     */
    private static array $implemented = [
        'payments' => ['razorpay' => Drivers\RazorpayGateway::class],
        'video' => ['jitsi' => Drivers\JitsiVideo::class],
        'ai' => ['anthropic' => Drivers\AnthropicDrafting::class],
    ];

    /** @return class-string|null the approved driver class, or null to stay switched off */
    public static function driverClass(string $service, string $driver): ?string
    {
        return self::$implemented[$service][$driver] ?? null;
    }

    /** @return list<array{key: string, name: string, purpose: string, driver: string, active: bool}> */
    public function status(): array
    {
        $rows = [];
        foreach (self::SERVICES as $key => [$name, $purpose]) {
            $driver = (string) config("integrations.{$key}.driver", 'none');
            $rows[] = ['key' => $key, 'name' => $name, 'purpose' => $purpose, 'driver' => $driver, 'active' => self::driverClass($key, $driver) !== null];
        }

        return $rows;
    }
}
