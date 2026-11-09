<?php

namespace App\Services;

use App\Models\ConsentPurpose;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(private RoleAssignmentService $roles, private ConsentLedger $consents) {}

    /** @param array{name: string, email: string, password: string, phone?: string|null} $data */
    public function register(array $data, string $channel = 'web'): User
    {
        $user = DB::transaction(function () use ($data, $channel) {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $user->forceFill(['status' => 'active'])->save();
            $this->roles->assignCustomer($user);

            // The mandatory purposes are what the sign-up checkbox agrees to.
            foreach (ConsentPurpose::query()->current()->where('is_mandatory', true)->get() as $purpose) {
                $this->consents->grant($user, $purpose, $channel);
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
