<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    /**
     * Get all events
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $events = Event::orderBy('event_date', 'asc')
            ->paginate(20);

        return response()->json($events);
    }

    /**
     * Get event details with availability
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function show(int $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);

        return response()->json([
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'total_tickets' => $event->total_tickets,
            'available_tickets' => $event->available_tickets,
            'price' => $event->price,
            'event_date' => $event->event_date->toIso8601String(),
            'availability_percentage' => $event->availability_percentage,
        ]);
    }
}

