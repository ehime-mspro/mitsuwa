<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * 決裁のみ利用者（設計書 D6）。
     *
     * ⚠ `must_change_password` は明示する側の責任（この state では触らない。
     *   既定に頼るとメモリ上は null・DB から引くと true になり経路で結果が変わる）。
     */
    public function approvalOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'role'             => \App\Enums\UserRole::ApprovalOnly->value,
            'email'            => null,
            'employee_number'  => 'A' . fake()->unique()->numberBetween(1000, 9999),
        ]);
    }
}
