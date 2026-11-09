<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RetentionRule extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['data_category', 'version', 'retention_months', 'trigger_event', 'on_expiry', 'on_erasure_request', 'legal_basis_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
