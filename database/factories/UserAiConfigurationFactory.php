<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAiConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAiConfiguration>
 */
class UserAiConfigurationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'provider' => $this->faker->randomElement(['gemini', 'anthropic', 'bedrock']),
            'api_key' => $this->faker->regexify('[A-Za-z0-9]{40}'),
            'region' => null,
            'session_token' => null,
            'model' => 'gemini-2.0-flash',
            'is_active' => false,
        ];
    }

    public function gemini(): static
    {
        return $this->state(['provider' => 'gemini', 'model' => 'gemini-2.0-flash', 'region' => null]);
    }

    public function anthropic(): static
    {
        return $this->state(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'region' => null]);
    }

    public function bedrock(): static
    {
        return $this->state(['provider' => 'bedrock', 'model' => 'anthropic.claude-sonnet-4-6', 'region' => 'us-east-1']);
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function expiredAt(\DateTimeInterface|string $date): static
    {
        return $this->state(['expires_at' => $date]);
    }
}
