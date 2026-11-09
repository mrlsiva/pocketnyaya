<?php

namespace App\Services;

use App\Models\AdvocateProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a solo practice or a firm. The creator gets an active membership and
 * the top role, plus an advocate profile (DA-7), which needs no verification (DA-8).
 */
class PracticeService
{
    public function __construct(private RoleAssignmentService $roles) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Tenant
    {
        $type = (string) $data['type'];

        if ($type === 'solo' && Tenant::query()->where(['type' => 'solo', 'owner_user_id' => $user->id])->exists()) {
            throw ValidationException::withMessages(['type' => 'You already have a solo practice.']);
        }

        return DB::transaction(function () use ($user, $data, $type) {
            $tenant = new Tenant(['name' => $data['name'], 'gst_registration_type' => 'unregistered', 'state_code' => $data['state_code']]);
            $tenant->forceFill([
                'type' => $type,
                'owner_user_id' => $user->id,
                'slug' => Str::slug((string) $data['name']).'-'.Str::lower(Str::random(6)),
            ])->save();

            DB::table('tenant_memberships')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->roles->assign($user, $type === 'solo' ? 'Independent Lawyer' : 'Managing Partner', $tenant->id);

            if (! AdvocateProfile::query()->where('user_id', $user->id)->exists()) {
                $user->advocateProfile()->create([
                    'bar_council_id' => $data['bar_council_id'],
                    'enrollment_number' => $data['enrollment_number'],
                    'enrollment_date' => $data['enrollment_date'],
                    'city_id' => $data['city_id'],
                ]);
            }

            return $tenant;
        });
    }
}
