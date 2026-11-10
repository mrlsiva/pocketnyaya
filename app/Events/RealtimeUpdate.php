<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A small "something changed" signal on a private channel (PRD 2.3: web and mobile stay in
 * step). It carries ids and a type only, never case content: the app fetches the details
 * itself, through the usual permission checks.
 */
class RealtimeUpdate implements ShouldBroadcastNow
{
    /** @param  array<string, string|int|null>  $data */
    public function __construct(public string $channel, public string $type, public array $data = []) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'update';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'data' => $this->data, 'at' => now()->toIso8601String()];
    }
}
