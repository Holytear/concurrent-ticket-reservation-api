<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalTickets = fake()->numberBetween(50, 1000);
        
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'total_tickets' => $totalTickets,
            'available_tickets' => $totalTickets,
            'price' => fake()->randomFloat(2, 10, 500),
            'event_date' => Carbon::now()->addDays(fake()->numberBetween(1, 90)),
        ];
    }

    /**
     * Indicate that the event is sold out.
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'available_tickets' => 0,
        ]);
    }

    /**
     * Indicate that the event has limited tickets.
     */
    public function limitedTickets(int $available): static
    {
        return $this->state(fn (array $attributes) => [
            'available_tickets' => $available,
        ]);
    }
}

