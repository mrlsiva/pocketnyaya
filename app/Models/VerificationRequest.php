<?php

namespace App\Models;

use Database\Factories\VerificationRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationRequest extends Model
{
    /** @use HasFactory<VerificationRequestFactory> */
    use HasFactory;

    use HasUuids;

    /** The applicant may add or replace documents only in these statuses. */
    public const OPEN_STATUSES = ['submitted', 'info_requested'];

    /** Staff may read documents only once review has started. */
    public const STAFF_VISIBLE_STATUSES = ['in_review', 'approved', 'rejected'];

    /**
     * Status, assignment and review fields belong to the verification workflow.
     *
     * @var list<string>
     */
    protected $fillable = ['submitted_at', 'checklist'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'checklist' => 'array',
        ];
    }

    /** @return BelongsTo<AdvocateProfile, $this> */
    public function advocateProfile(): BelongsTo
    {
        return $this->belongsTo(AdvocateProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return BelongsTo<MediatorProfile, $this> */
    public function mediatorProfile(): BelongsTo
    {
        return $this->belongsTo(MediatorProfile::class);
    }

    /** The advocate or mediator who applied. */
    public function applicantUserId(): ?string
    {
        return $this->advocateProfile->user_id ?? $this->mediatorProfile->user_id ?? null;
    }

    public function acceptsDocuments(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
