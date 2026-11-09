<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MatterParty extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['party_role', 'name', 'is_our_client', 'counsel_name', 'contact', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_our_client' => 'boolean', 'contact' => 'encrypted'];
    }
}
