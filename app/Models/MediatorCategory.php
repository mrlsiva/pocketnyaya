<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pivot to ODR category codes, with a composite key of profile and category.
 */
class MediatorCategory extends Model
{
    /** Codes match odr_disputes.category in the approved design. */
    public const CODES = ['small_claims', 'landlord_tenant', 'ecommerce', 'service', 'creator_brand', 'other'];

    protected $primaryKey = 'category';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['category'];

    /** @return BelongsTo<MediatorProfile, $this> */
    public function mediatorProfile(): BelongsTo
    {
        return $this->belongsTo(MediatorProfile::class);
    }

    /**
     * Updates and deletes must match both key columns, so changing one
     * mediator's category can never touch another mediator's row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('mediator_profile_id', $this->getOriginal('mediator_profile_id'))
            ->where('category', $this->getOriginal('category'));
    }
}
