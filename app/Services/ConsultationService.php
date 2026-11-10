<?php

namespace App\Services;

use App\Models\AdvocateProfile;
use App\Models\Client;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\AccessContext;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationService
{
    public function __construct(private MatterService $matters) {}

    /** @param array<string, mixed> $data */
    public function request(User $customer, AdvocateProfile $advocate, array $data): ConsultationRequest
    {
        if ($advocate->user_id === $customer->id) {
            throw ValidationException::withMessages(['advocate' => 'You cannot request a consultation with yourself.']);
        }

        $addressee = User::query()->findOrFail($advocate->user_id);
        $membership = $this->practiceMembership($advocate, $data['tenant_id'] ?? null);

        // The addressee holds consultations.receive and an active membership (D8.5).
        if ($membership === null || ! AccessContext::citizenCan($addressee, 'consultations.receive')) {
            throw ValidationException::withMessages(['advocate' => 'This advocate is not accepting consultations.']);
        }

        $consultation = new ConsultationRequest($data);
        $consultation->forceFill([
            'customer_user_id' => $customer->id,
            'advocate_profile_id' => $advocate->id,
            'tenant_id' => $membership->tenant_id,
            'advocate_membership_id' => $membership->id,
            'status' => 'requested',
        ])->save();

        return $consultation;
    }

    public function respond(ConsultationRequest $consultation, string $status, ?string $scheduledAt = null): void
    {
        if (! in_array($status, ConsultationRequest::TRANSITIONS[$consultation->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'That change is not valid for this consultation.']);
        }
        if ($status === 'scheduled' && $scheduledAt === null) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a date and time.']);
        }

        $consultation->forceFill(['status' => $status] + ($status === 'scheduled' ? ['scheduled_at' => $scheduledAt] : []))->save();
    }

    public function message(ConsultationRequest $consultation, User $sender, string $body): ConsultationMessage
    {
        if (! $consultation->acceptsMessages()) {
            throw ValidationException::withMessages(['body' => 'This consultation no longer accepts messages.']);
        }

        $message = new ConsultationMessage(['body' => $body]);
        $message->forceFill(['consultation_request_id' => $consultation->id, 'sender_user_id' => $sender->id])->save();

        // Tell the other side: the customer, or the advocate the consultation is assigned to.
        $advocate = $consultation->advocate_membership_id === null ? null : (string) DB::table('tenant_memberships')->where('id', $consultation->advocate_membership_id)->value('user_id');
        foreach (array_filter([$consultation->customer_user_id, $advocate]) as $recipient) {
            if ($recipient !== $sender->id) {
                Realtime::toUser($recipient, 'consultation.message', ['consultation_id' => $consultation->id]);
            }
        }

        return $message;
    }

    /** Turns an accepted consultation into a client and a matter in the practice. */
    public function convert(ConsultationRequest $consultation, User $user): Matter
    {
        if (! in_array($consultation->status, ['accepted', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only an accepted or completed consultation can become a matter.']);
        }

        return DB::transaction(function () use ($consultation, $user) {
            $client = $consultation->client_id !== null
                ? Client::query()->findOrFail($consultation->client_id)
                : $this->linkedClient($consultation);

            $matter = $this->matters->create($user, [
                'client_id' => $client->id,
                'title' => $consultation->subject,
                'matter_type' => 'other',
                'priority' => 'normal',
                'description' => $consultation->description,
            ]);
            $consultation->forceFill(['client_id' => $client->id])->save();

            return $matter;
        });
    }

    private function linkedClient(ConsultationRequest $consultation): Client
    {
        /** @var User $customer */
        $customer = $consultation->customer;

        $existing = Client::query()->where(['tenant_id' => $consultation->tenant_id, 'customer_user_id' => $customer->id])->first();
        if ($existing !== null) {
            return $existing;
        }

        $state = DB::table('tenants')->where('id', $consultation->tenant_id)->value('state_code');
        $client = new Client(['client_type' => 'individual', 'name' => $customer->name, 'email' => $customer->email, 'state_code' => $state]);
        $client->forceFill(['tenant_id' => $consultation->tenant_id, 'customer_user_id' => $customer->id])->save();

        return $client;
    }

    private function practiceMembership(AdvocateProfile $advocate, mixed $tenantId): ?object
    {
        return DB::table('tenant_memberships as m')->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.user_id', $advocate->user_id)->where('m.status', 'active')->whereNull('m.deleted_at')
            ->where('t.status', 'active')->whereIn('t.type', ['solo', 'firm'])
            ->when(is_string($tenantId), fn ($q) => $q->where('m.tenant_id', $tenantId))
            ->orderBy('t.type', 'desc')->first(['m.id', 'm.tenant_id']);
    }
}
