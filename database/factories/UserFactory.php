<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
     * ⚠ `status` は明示する（Task 5 実装時に判明）。DB 列の既定値はずっと 'active' だったが、
     *   factory がそれを反映していなかったため、`status` を渡さずに `create()` した User は
     *   in-memory では `$user->status` が未設定＝ null のままになる（Eloquent は insert 後に
     *   サーバ側の既定値を読み直さない）。`actingAs()` はその PHP オブジェクトをそのまま
     *   Guard に積む（DB から取り直さない）ため、`EnsureUserIsActive::isActive()` が false と
     *   誤判定し、既存テストの大半（本来は有効な利用者のつもり）が最初のリクエストから
     *   ログアウトさせられていた。実際のログイン経路（`Auth::attempt` → `retrieveById`）は
     *   毎回 DB から全カラムを取り直すため本番では起きない、テスト特有の罠（`must_change_password`
     *   と同種。あちらは意図的に既定値を持たせていないが、`status` は DB の既定 'active' と
     *   常に一致させたいのでここで揃える）。
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
            'status' => UserStatus::Active->value,
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
            'role'             => UserRole::ApprovalOnly->value,
            'email'            => null,
            'employee_number'  => 'A' . fake()->unique()->numberBetween(1000, 9999),
        ]);
    }
}
