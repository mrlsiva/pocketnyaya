<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['line_type', 'description', 'quantity', 'unit_price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tax_rule_snapshot' => 'array'];
    }
}
