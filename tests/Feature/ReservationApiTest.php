<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_get_event_details()
    {
        $event = Event::factory()->create([
            'name' => 'Laravel Conference',
            'available_tickets' => 100,
        ]);

        $response = $this->getJson("/api/events/{$event->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $event->id,
                'name' => 'Laravel Conference',
                'available_tickets' => 100,
            ]);
    }

    /** @test */
    public function it_requires_authentication_to_reserve_ticket()
    {
        $event = Event::factory()->create();

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(401);
    }

    /** @test */
    public function authenticated_user_can_reserve_ticket()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(201)
            ->assertJsonStructure([
                'reservation_id',
                'event_id',
                'status',
                'expires_at',
                'time_remaining_seconds',
                'message',
            ]);
    }

    /** @test */
    public function it_returns_conflict_when_no_tickets_available()
    {
        $event = Event::factory()->create([
            'available_tickets' => 0,
        ]);
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(409)
            ->assertJson([
                'error' => 'No tickets available',
            ]);
    }

    /** @test */
    public function authenticated_user_can_purchase_reserved_ticket()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Reserve a ticket
        $reserveResponse = $this->postJson("/api/events/{$event->id}/reserve");
        $reservationId = $reserveResponse->json('reservation_id');

        // Purchase the ticket
        $response = $this->postJson("/api/reservations/{$reservationId}/purchase");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'purchased',
                'message' => 'Ticket purchased successfully!',
            ]);
    }

    /** @test */
    public function it_can_get_user_reservations()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $event = Event::factory()->create(['available_tickets' => 100]);
        
        // Reserve a ticket
        $this->postJson("/api/events/{$event->id}/reserve");

        // Get user's reservations
        $response = $this->getJson('/api/reservations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'reservations' => [
                    '*' => [
                        'id',
                        'event',
                        'status',
                        'expires_at',
                    ],
                ],
            ]);
    }

    /** @test */
    public function it_can_cancel_a_reservation()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Reserve a ticket
        $reserveResponse = $this->postJson("/api/events/{$event->id}/reserve");
        $reservationId = $reserveResponse->json('reservation_id');

        // Cancel the reservation
        $response = $this->deleteJson("/api/reservations/{$reservationId}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Reservation cancelled successfully.',
            ]);
    }
}

