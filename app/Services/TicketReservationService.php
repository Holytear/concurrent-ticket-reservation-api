<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Exceptions\EventNotFoundException;
use App\Exceptions\ReservationNotFoundException;
use App\Exceptions\InvalidReservationException;
use App\Exceptions\ExpiredReservationException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TicketReservationService
{
    /**
     * Reserve a ticket for an event
     * 
     * Uses pessimistic locking to prevent race conditions
     * 
     * @param int $eventId
     * @param int $userId
     * @return Reservation|null
     * @throws EventNotFoundException
     */
    public function reserveTicket(int $eventId, int $userId): ?Reservation
    {
        return DB::transaction(function () use ($eventId, $userId) {
            // Step 1: Lock the event row and check availability
            $event = Event::where('id', $eventId)
                ->lockForUpdate()  // SELECT ... FOR UPDATE (Pessimistic Lock)
                ->first();
            
            if (!$event) {
                throw new EventNotFoundException("Event with ID {$eventId} not found");
            }
            
            // Step 2: Check if tickets are available
            if ($event->available_tickets <= 0) {
                return null; // No tickets available
            }
            
            // Step 3: Check if user already has an active reservation for this event
            $existingReservation = Reservation::where('event_id', $eventId)
                ->where('user_id', $userId)
                ->where('status', Reservation::STATUS_RESERVED)
                ->where('expires_at', '>', Carbon::now())
                ->first();
            
            if ($existingReservation) {
                return $existingReservation; // User already has a reservation
            }
            
            // Step 4: Decrement available tickets atomically
            $event->decrement('available_tickets');
            
            // Step 5: Create the reservation
            $reservation = Reservation::create([
                'event_id' => $eventId,
                'user_id' => $userId,
                'status' => Reservation::STATUS_RESERVED,
                'expires_at' => Carbon::now()->addMinutes(Reservation::EXPIRATION_MINUTES),
            ]);
            
            return $reservation;
        });
    }
    
    /**
     * Purchase a reserved ticket
     * 
     * @param int $reservationId
     * @param int $userId
     * @return bool
     * @throws ReservationNotFoundException
     * @throws InvalidReservationException
     * @throws ExpiredReservationException
     */
    public function purchaseTicket(int $reservationId, int $userId): bool
    {
        return DB::transaction(function () use ($reservationId, $userId) {
            // Lock the reservation to prevent concurrent purchases
            $reservation = Reservation::where('id', $reservationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                throw new ReservationNotFoundException("Reservation with ID {$reservationId} not found");
            }
            
            // Check if reservation is still in reserved status
            if ($reservation->status !== Reservation::STATUS_RESERVED) {
                throw new InvalidReservationException("Reservation already processed with status: {$reservation->status}");
            }
            
            // Check if reservation has expired
            if ($reservation->expires_at < Carbon::now()) {
                throw new ExpiredReservationException("Reservation expired at {$reservation->expires_at}");
            }
            
            // Mark as purchased
            $reservation->update([
                'status' => Reservation::STATUS_PURCHASED,
                'purchased_at' => Carbon::now(),
            ]);
            
            return true;
        });
    }

    /**
     * Cancel a reservation and return ticket to pool
     * 
     * @param int $reservationId
     * @param int $userId
     * @return bool
     * @throws ReservationNotFoundException
     * @throws InvalidReservationException
     */
    public function cancelReservation(int $reservationId, int $userId): bool
    {
        return DB::transaction(function () use ($reservationId, $userId) {
            // Lock the reservation
            $reservation = Reservation::where('id', $reservationId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
            
            if (!$reservation) {
                throw new ReservationNotFoundException("Reservation with ID {$reservationId} not found");
            }
            
            // Can only cancel reserved tickets
            if ($reservation->status !== Reservation::STATUS_RESERVED) {
                throw new InvalidReservationException("Can only cancel reserved tickets");
            }
            
            // Mark as cancelled
            $reservation->update(['status' => Reservation::STATUS_CANCELLED]);
            
            // Return ticket to available pool
            Event::where('id', $reservation->event_id)
                ->increment('available_tickets');
            
            return true;
        });
    }

    /**
     * Release expired reservations for a specific event
     * Helper method that can be called on-demand
     * 
     * @param int $eventId
     * @return int Number of reservations released
     */
    public function releaseExpiredForEvent(int $eventId): int
    {
        return DB::transaction(function () use ($eventId) {
            $expiredReservations = Reservation::where('event_id', $eventId)
                ->where('status', Reservation::STATUS_RESERVED)
                ->where('expires_at', '<', Carbon::now())
                ->lockForUpdate()
                ->get();
            
            $releasedCount = 0;
            
            foreach ($expiredReservations as $reservation) {
                $reservation->update(['status' => Reservation::STATUS_EXPIRED]);
                
                Event::where('id', $reservation->event_id)
                    ->increment('available_tickets');
                
                $releasedCount++;
            }
            
            return $releasedCount;
        });
    }
}

