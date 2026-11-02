<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TaxRate extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $primaryKey = 'tax_rate_id';

    #[\Override]
    protected $fillable = [
        'name',
        'rate',
        'description',
        'is_compound',
        'is_active',
    ];

    #[\Override]
    protected $casts = [
        'rate' => 'float',
        'is_compound' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function calculateTax($amount, $previousTaxes = 0): float|int
    {
        if (! $this->is_active) {
            return 0;
        }

        $taxableAmount = $this->is_compound ?
            $amount + $previousTaxes :
            $amount;

        return $taxableAmount * ($this->rate / 100);
    }

    /**
     * @return HasManyThrough<Invoice, InvoiceItem, $this>
     */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'tax_rate_id', 'id', 'tax_rate_id', 'invoice_id')->distinct();
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_tax_rate', 'tax_rate_id', 'customer_id', 'tax_rate_id', 'id');
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_tax_rate', 'tax_rate_id', 'supplier_id', 'tax_rate_id', 'supplier_id');
    }
}
