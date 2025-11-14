<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'expires_at',
        'purchased_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'purchased_at' => 'datetime',
    ];

    /**
     * Reservation statuses
     */
    const STATUS_RESERVED = 'reserved';
    const STATUS_PURCHASED = 'purchased';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Reservation expiration time in minutes
     */
    const EXPIRATION_MINUTES = 5;

    /**
     * Get the event this reservation belongs to
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the user who made this reservation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if reservation is expired
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_RESERVED 
            && $this->expires_at < Carbon::now();
    }

    /**
     * Check if reservation is active (not expired, not cancelled)
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_RESERVED 
            && $this->expires_at > Carbon::now();
    }

    /**
     * Check if reservation is purchased
     */
    public function isPurchased(): bool
    {
        return $this->status === self::STATUS_PURCHASED;
    }

    /**
     * Get time remaining before expiration in seconds
     */
    public function getTimeRemainingAttribute(): int
    {
        if (!$this->isActive()) {
            return 0;
        }

        return max(0, $this->expires_at->diffInSeconds(Carbon::now()));
    }

    /**
     * Scope: Get only active reservations
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_RESERVED)
            ->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope: Get only expired reservations
     */
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_RESERVED)
            ->where('expires_at', '<', Carbon::now());
    }
}

