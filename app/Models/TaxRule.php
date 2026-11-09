<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRule extends Model
{
    use HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'sac_code', 'supplier_registration', 'recipient_type', 'recipient_turnover', 'supply_type', 'charge_mechanism', 'cgst_rate', 'sgst_rate', 'igst_rate', 'invoice_type', 'priority', 'effective_from', 'effective_to', 'legal_reference'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
