<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasDocuments;
use App\Contracts\Documentable;
use App\Traits\IsTenantModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int|null $team_id
 * @property string|null $qbo_id
 * @property string|null $xero_id
 * @property string|null $sage_id
 * @property-read TaxRate|null $taxRate
 * @property-read Collection<int, EstimateItem> $items
 * @property-read Invoice|null $invoice
 */
class Estimate extends Model implements Documentable
{
    use HasDocuments;
    use HasFactory, SoftDeletes;
    use IsTenantModel;

    #[\Override]
    protected $primaryKey = 'estimate_id';

    #[\Override]
    protected $fillable = [
        'customer_id',
        'estimate_number',
        'estimate_date',
        'expiration_date',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'tax_rate_id',
        'status',
        'invoice_id',
        'sent_at',
        'viewed_at',
        'accepted_at',
        'declined_at',
        'decline_reason',
        'notes',
        'terms',
        'document_path',
        'team_id',
        'qbo_id',
        'xero_id',
        'sage_id',
    ];

    #[\Override]
    protected $casts = [
        'estimate_date' => 'date',
        'expiration_date' => 'date',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function taxRate()
    {
        // Explicit keys: TaxRate's PK is tax_rate_id, so Laravel's guessed FK
        // (tax_rate_tax_rate_id) is wrong and would always resolve to null.
        return $this->belongsTo(TaxRate::class, 'tax_rate_id', 'tax_rate_id');
    }

    /**
     * @return HasMany<EstimateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class, 'estimate_id', 'estimate_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class, 'estimate_id', 'estimate_id');
    }

    // Calculated Attributes
    public function getIsExpiredAttribute()
    {
        if (! $this->expiration_date || $this->status === 'accepted') {
            return false;
        }

        return Carbon::now()->isAfter($this->expiration_date);
    }

    public function getDaysUntilExpirationAttribute(): ?float
    {
        if (! $this->expiration_date) {
            return null;
        }

        return Carbon::now()->diffInDays($this->expiration_date, false);
    }

    // Business Logic Methods
    public function calculateTax()
    {
        if (! $this->taxRate) {
            $this->tax_amount = $this->items()->sum('tax_amount');
            $this->total_amount = $this->subtotal_amount + $this->tax_amount;

            return (float) $this->tax_amount;
        }

        $baseAmount = $this->subtotal_amount;
        $previousTaxes = 0;

        if ($this->taxRate->is_compound) {
            $nonCompoundTaxes = TaxRate::where('team_id', $this->team_id)
                ->where('is_active', true)
                ->where('is_compound', false)
                ->get();

            foreach ($nonCompoundTaxes as $tax) {
                $previousTaxes += $tax->calculateTax($baseAmount);
            }
        }

        $taxAmount = $this->taxRate->calculateTax($baseAmount, $previousTaxes);
        $this->tax_amount = $taxAmount;
        $this->total_amount = $this->subtotal_amount + $taxAmount;

        return $taxAmount;
    }

    public function calculateTotals(): void
    {
        $this->subtotal_amount = $this->items()->sum('amount');
        $this->calculateTax();
        $this->save();
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function markAsViewed(): void
    {
        if (! $this->viewed_at) {
            $this->update([
                'status' => 'viewed',
                'viewed_at' => now(),
            ]);
        }
    }

    public function accept(): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function decline($reason = null): void
    {
        $this->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);
    }

    public function convertToInvoice(): Invoice
    {
        return DB::transaction(function (): Invoice {
            $estimate = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($estimate->status !== 'accepted') {
                throw new \DomainException('Only accepted estimates can be converted to invoices.');
            }
            if ($estimate->invoice_id) {
                throw new \DomainException('This estimate has already been converted to an invoice.');
            }
            if ($estimate->salesOrder()->exists()) {
                throw new \DomainException('This estimate already has a sales order.');
            }
            if ($estimate->team_id === null || $estimate->customer === null
                || (int) $estimate->customer->team_id !== (int) $estimate->team_id) {
                throw new \DomainException('Estimate and customer must belong to the same company.');
            }
            $items = $estimate->items()->get();
            if ($items->isEmpty()) {
                throw new \DomainException('An estimate requires line items before invoicing.');
            }

            $invoice = Invoice::create([
                'customer_id' => $estimate->customer_id,
                'team_id' => $estimate->team_id,
                'invoice_date' => today(),
                'due_date' => today()->addDays(30),
                'total_amount' => $estimate->subtotal_amount,
                'payment_status' => 'pending',
                'notes' => "Converted from Estimate {$estimate->estimate_number}",
            ]);
            foreach ($items as $item) {
                $invoice->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_amount' => $item->tax_amount,
                    'tax_rate_id' => $estimate->tax_rate_id ?? $item->tax_rate_id,
                ]);
            }
            $invoice->refresh();
            $gross = bcadd((string) $invoice->total_amount, (string) $invoice->items()->sum('tax_amount'), 2);
            if (bccomp((string) $invoice->total_amount, (string) $estimate->subtotal_amount, 2) !== 0
                || bccomp($gross, (string) $estimate->total_amount, 2) !== 0) {
                throw new \DomainException('Invoice totals must match the accepted estimate.');
            }
            $estimate->update(['invoice_id' => $invoice->getKey()]);
            $this->refresh();

            return $invoice;
        });
    }

    // Scopes
    public function scopeExpired($query)
    {
        return $query->where('expiration_date', '<', now())
            ->whereNotIn('status', ['accepted', 'declined']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'viewed'])
            ->where(function ($q): void {
                $q->whereNull('expiration_date')
                    ->orWhere('expiration_date', '>=', now());
            });
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // Auto-generate estimate number and check expiration
    #[\Override]
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($estimate): void {
            if (empty($estimate->estimate_number)) {
                $estimate->estimate_number = static::generateEstimateNumber();
            }
            if (empty($estimate->status)) {
                $estimate->status = 'draft';
            }
        });

        static::saving(function ($estimate): void {
            // Auto-expire if past expiration date
            if ($estimate->expiration_date &&
                Carbon::now()->isAfter($estimate->expiration_date) &&
                ! in_array($estimate->status, ['accepted', 'declined', 'expired'])) {
                $estimate->status = 'expired';
            }
        });
    }

    public static function generateEstimateNumber(): string
    {
        $prefix = 'EST';
        $year = date('Y');
        $lastEstimate = static::whereYear('created_at', $year)
            ->orderBy('estimate_number', 'desc')
            ->first();

        if (! $lastEstimate) {
            $number = 1;
        } else {
            $parts = explode('-', (string) $lastEstimate->estimate_number);
            $number = isset($parts[1]) ? ((int) $parts[1]) + 1 : 1;
        }

        return $prefix.$year.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
