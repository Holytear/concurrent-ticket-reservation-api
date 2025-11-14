<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'total_tickets',
        'available_tickets',
        'price',
        'event_date',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'price' => 'decimal:2',
        'total_tickets' => 'integer',
        'available_tickets' => 'integer',
    ];

    /**
     * Get all reservations for this event
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Get active reservations (not expired, not cancelled)
     */
    public function activeReservations(): HasMany
    {
        return $this->reservations()
            ->where('status', 'reserved')
            ->where('expires_at', '>', now());
    }

    /**
     * Get purchased tickets
     */
    public function purchasedTickets(): HasMany
    {
        return $this->reservations()->where('status', 'purchased');
    }

    /**
     * Check if tickets are available
     */
    public function hasAvailableTickets(): bool
    {
        return $this->available_tickets > 0;
    }

    /**
     * Get availability percentage
     */
    public function getAvailabilityPercentageAttribute(): float
    {
        if ($this->total_tickets === 0) {
            return 0;
        }

        return round(($this->available_tickets / $this->total_tickets) * 100, 2);
    }
}

