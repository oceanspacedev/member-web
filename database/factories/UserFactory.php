<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '628'.fake()->unique()->numerify('##########'),
            'email' => fake()->unique()->safeEmail(),
            'birth_date' => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'phone_verified_at' => now(),
        ];
    }

    public function cashier(?int $outletId = null): static
    {
        return $this->state(fn () => ['outlet_id' => $outletId])
            ->afterMaking(fn (User $user) => $user->role = User::ROLE_CASHIER);
    }
}
