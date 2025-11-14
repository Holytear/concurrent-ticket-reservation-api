<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'status' => Reservation::STATUS_RESERVED,
            'expires_at' => Carbon::now()->addMinutes(Reservation::EXPIRATION_MINUTES),
            'purchased_at' => null,
        ];
    }

    /**
     * Indicate that the reservation is purchased.
     */
    public function purchased(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reservation::STATUS_PURCHASED,
            'purchased_at' => Carbon::now(),
        ]);
    }

    /**
     * Indicate that the reservation is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reservation::STATUS_EXPIRED,
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);
    }

    /**
     * Indicate that the reservation is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reservation::STATUS_CANCELLED,
        ]);
    }
}

