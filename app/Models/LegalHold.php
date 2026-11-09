<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LegalHold extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['holdable_type', 'holdable_id', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public static function isActive(string $type, string $id): bool
    {
        return self::query()->where(['holdable_type' => $type, 'holdable_id' => $id])->whereNull('released_at')->exists();
    }

    /**
     * Any active hold that reaches this document: on the document itself, its practice,
     * its matter, its dispute, or (for a personal document) its owner. C60 §4.2.
     */
    public static function coversDocument(Document $document): bool
    {
        return self::anyActive(array_filter([
            'document' => $document->id,
            'tenant' => $document->tenant_id,
            'matter' => $document->matter_id,
            'odr_dispute' => $document->odr_dispute_id,
            'user' => $document->scope === 'personal' ? $document->owner_user_id : null,
        ]));
    }

    /** A hold on the matter or on its practice. */
    public static function coversMatter(Matter $matter): bool
    {
        return self::anyActive(['matter' => $matter->id, 'tenant' => $matter->tenant_id]);
    }

    /** A hold on the practice, or on any of this client's matters. */
    public static function coversClient(Client $client): bool
    {
        return self::anyActive(['tenant' => $client->tenant_id])
            || self::query()->where('holdable_type', 'matter')->whereNull('released_at')
                ->whereIn('holdable_id', Matter::withTrashed()->where('client_id', $client->id)->pluck('id'))->exists();
    }

    /** @param  array<string, string|null>  $targets  holdable_type => holdable_id */
    private static function anyActive(array $targets): bool
    {
        $targets = array_filter($targets, fn ($id) => $id !== null && $id !== '');
        if ($targets === []) {
            return false;
        }

        return self::query()->whereNull('released_at')->where(function ($q) use ($targets) {
            foreach ($targets as $type => $id) {
                $q->orWhere(fn ($w) => $w->where('holdable_type', $type)->where('holdable_id', $id));
            }
        })->exists();
    }
}
