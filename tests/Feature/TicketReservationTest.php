<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Reservation;
use App\Services\TicketReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class TicketReservationTest extends TestCase
{
    use RefreshDatabase;

    protected TicketReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TicketReservationService();
    }

    /** @test */
    public function it_can_reserve_a_ticket()
    {
        $event = Event::factory()->create([
            'total_tickets' => 100,
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();

        $reservation = $this->service->reserveTicket($event->id, $user->id);

        $this->assertNotNull($reservation);
        $this->assertEquals('reserved', $reservation->status);
        $this->assertEquals(99, $event->fresh()->available_tickets);
    }

    /** @test */
    public function it_prevents_overselling_tickets()
    {
        $event = Event::factory()->create([
            'total_tickets' => 1,
            'available_tickets' => 1,
        ]);
        
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // First reservation should succeed
        $reservation1 = $this->service->reserveTicket($event->id, $user1->id);
        $this->assertNotNull($reservation1);

        // Second reservation should fail
        $reservation2 = $this->service->reserveTicket($event->id, $user2->id);
        $this->assertNull($reservation2);

        // Available tickets should be 0
        $this->assertEquals(0, $event->fresh()->available_tickets);
    }

    /** @test */
    public function it_prevents_user_from_reserving_multiple_tickets_for_same_event()
    {
        $event = Event::factory()->create([
            'total_tickets' => 100,
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();

        // First reservation
        $reservation1 = $this->service->reserveTicket($event->id, $user->id);
        
        // Second attempt should return the existing reservation
        $reservation2 = $this->service->reserveTicket($event->id, $user->id);

        $this->assertEquals($reservation1->id, $reservation2->id);
        $this->assertEquals(99, $event->fresh()->available_tickets);
    }

    /** @test */
    public function it_can_purchase_a_reserved_ticket()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        $reservation = $this->service->reserveTicket($event->id, $user->id);

        $result = $this->service->purchaseTicket($reservation->id, $user->id);

        $this->assertTrue($result);
        $this->assertEquals('purchased', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->purchased_at);
    }

    /** @test */
    public function it_cannot_purchase_an_expired_reservation()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        $reservation = $this->service->reserveTicket($event->id, $user->id);

        // Manually expire the reservation
        $reservation->update([
            'expires_at' => Carbon::now()->subMinutes(1),
        ]);

        $this->expectException(\App\Exceptions\ExpiredReservationException::class);
        $this->service->purchaseTicket($reservation->id, $user->id);
    }

    /** @test */
    public function it_can_cancel_a_reservation()
    {
        $event = Event::factory()->create([
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        $reservation = $this->service->reserveTicket($event->id, $user->id);

        $initialAvailable = $event->fresh()->available_tickets;

        $result = $this->service->cancelReservation($reservation->id, $user->id);

        $this->assertTrue($result);
        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertEquals($initialAvailable + 1, $event->fresh()->available_tickets);
    }

    /** @test */
    public function it_releases_expired_reservations()
    {
        $event = Event::factory()->create([
            'total_tickets' => 100,
            'available_tickets' => 100,
        ]);
        
        $user = User::factory()->create();
        
        // Create a reservation
        $reservation = $this->service->reserveTicket($event->id, $user->id);
        $this->assertEquals(99, $event->fresh()->available_tickets);

        // Manually expire it
        $reservation->update([
            'expires_at' => Carbon::now()->subMinutes(1),
        ]);

        // Release expired reservations
        $releasedCount = $this->service->releaseExpiredForEvent($event->id);

        $this->assertEquals(1, $releasedCount);
        $this->assertEquals(100, $event->fresh()->available_tickets);
        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    /** @test */
    public function it_handles_concurrent_reservations_correctly()
    {
        $event = Event::factory()->create([
            'total_tickets' => 10,
            'available_tickets' => 10,
        ]);
        
        // Create 20 users trying to reserve 10 tickets
        $users = User::factory()->count(20)->create();
        
        $successfulReservations = 0;
        $failedReservations = 0;

        foreach ($users as $user) {
            $reservation = $this->service->reserveTicket($event->id, $user->id);
            
            if ($reservation) {
                $successfulReservations++;
            } else {
                $failedReservations++;
            }
        }

        // Should have exactly 10 successful reservations
        $this->assertEquals(10, $successfulReservations);
        $this->assertEquals(10, $failedReservations);
        $this->assertEquals(0, $event->fresh()->available_tickets);
    }
}

