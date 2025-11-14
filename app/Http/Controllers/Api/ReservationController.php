<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TicketReservationService;
use App\Models\Reservation;
use App\Exceptions\EventNotFoundException;
use App\Exceptions\ReservationNotFoundException;
use App\Exceptions\InvalidReservationException;
use App\Exceptions\ExpiredReservationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    protected TicketReservationService $ticketService;

    public function __construct(TicketReservationService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    /**
     * Reserve a ticket for an event
     * 
     * @param Request $request
     * @param int $eventId
     * @return JsonResponse
     */
    public function reserve(Request $request, int $eventId): JsonResponse
    {
        $userId = $request->user()->id;
        
        try {
            // Optionally release expired tickets before attempting reservation
            $this->ticketService->releaseExpiredForEvent($eventId);
            
            $reservation = $this->ticketService->reserveTicket($eventId, $userId);
            
            if (!$reservation) {
                return response()->json([
                    'error' => 'No tickets available',
                    'message' => 'All tickets for this event are currently reserved or sold out.',
                ], 409); // 409 Conflict
            }
            
            return response()->json([
                'reservation_id' => $reservation->id,
                'event_id' => $reservation->event_id,
                'status' => $reservation->status,
                'expires_at' => $reservation->expires_at->toIso8601String(),
                'time_remaining_seconds' => $reservation->time_remaining,
                'message' => 'Ticket reserved successfully. Please complete purchase within 5 minutes.',
            ], 201); // 201 Created
            
        } catch (EventNotFoundException $e) {
            return response()->json([
                'error' => 'Event not found',
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal server error',
                'message' => 'An error occurred while processing your reservation.',
            ], 500);
        }
    }

    /**
     * Purchase a reserved ticket
     * 
     * @param Request $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function purchase(Request $request, int $reservationId): JsonResponse
    {
        $userId = $request->user()->id;
        
        try {
            $this->ticketService->purchaseTicket($reservationId, $userId);
            
            $reservation = Reservation::with('event')->find($reservationId);
            
            return response()->json([
                'reservation_id' => $reservation->id,
                'event_id' => $reservation->event_id,
                'event_name' => $reservation->event->name,
                'status' => $reservation->status,
                'purchased_at' => $reservation->purchased_at->toIso8601String(),
                'message' => 'Ticket purchased successfully!',
            ], 200);
            
        } catch (ReservationNotFoundException $e) {
            return response()->json([
                'error' => 'Reservation not found',
                'message' => $e->getMessage(),
            ], 404);
            
        } catch (ExpiredReservationException $e) {
            return response()->json([
                'error' => 'Reservation has expired',
                'message' => 'Your reservation has expired. Please try reserving again.',
            ], 410); // 410 Gone
            
        } catch (InvalidReservationException $e) {
            return response()->json([
                'error' => 'Invalid reservation',
                'message' => $e->getMessage(),
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal server error',
                'message' => 'An error occurred while processing your purchase.',
            ], 500);
        }
    }

    /**
     * Cancel a reservation
     * 
     * @param Request $request
     * @param int $reservationId
     * @return JsonResponse
     */
    public function cancel(Request $request, int $reservationId): JsonResponse
    {
        $userId = $request->user()->id;
        
        try {
            $this->ticketService->cancelReservation($reservationId, $userId);
            
            return response()->json([
                'message' => 'Reservation cancelled successfully.',
            ], 200);
            
        } catch (ReservationNotFoundException $e) {
            return response()->json([
                'error' => 'Reservation not found',
                'message' => $e->getMessage(),
            ], 404);
            
        } catch (InvalidReservationException $e) {
            return response()->json([
                'error' => 'Invalid reservation',
                'message' => $e->getMessage(),
            ], 400);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Internal server error',
                'message' => 'An error occurred while cancelling your reservation.',
            ], 500);
        }
    }

    /**
     * Get user's reservations
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        
        $reservations = Reservation::with('event')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'reservations' => $reservations->map(function ($reservation) {
                return [
                    'id' => $reservation->id,
                    'event' => [
                        'id' => $reservation->event->id,
                        'name' => $reservation->event->name,
                        'event_date' => $reservation->event->event_date->toIso8601String(),
                    ],
                    'status' => $reservation->status,
                    'expires_at' => $reservation->expires_at?->toIso8601String(),
                    'purchased_at' => $reservation->purchased_at?->toIso8601String(),
                    'is_active' => $reservation->isActive(),
                    'time_remaining_seconds' => $reservation->time_remaining,
                ];
            }),
        ]);
    }
}

