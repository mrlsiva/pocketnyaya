<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsultationRequest extends Model
{
    use HasUuids, SoftDeletes;

    /** Valid status changes for the lawyer's side. */
    public const TRANSITIONS = [
        'requested' => ['accepted', 'declined'],
        'accepted' => ['scheduled', 'completed', 'cancelled'],
        'scheduled' => ['completed', 'cancelled'],
    ];

    /** @var list<string> */
    protected $fillable = ['mode', 'subject', 'description', 'preferred_slots'];

    /** Never included in anything sent to the customer. */
    /** @var list<string> */
    protected $hidden = ['lawyer_private_notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'lawyer_private_notes' => 'encrypted',
            'preferred_slots' => 'array',
            'scheduled_at' => 'datetime',
        ];
    }

    public function acceptsMessages(): bool
    {
        return ! in_array($this->status, ['cancelled', 'declined', 'expired'], true);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<AdvocateProfile, $this> */
    public function advocateProfile(): BelongsTo
    {
        return $this->belongsTo(AdvocateProfile::class);
    }

    /** @return HasMany<ConsultationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class);
    }
}
