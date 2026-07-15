<?php

declare(strict_types=1);

namespace Zvizvi\FilamentVueTable\Tests\Fixtures\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Zvizvi\FilamentVueTable\Tests\Fixtures\Models\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'status' => fake()->randomElement(['active', 'pending', 'archived']),
            'is_admin' => fake()->boolean(20),
            'avatar_url' => 'https://avatars.example.test/'.fake()->uuid().'.jpg',
            'is_banned' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }

    public function banned(): static
    {
        return $this->state(['is_banned' => true]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'blocked']);
    }
}
