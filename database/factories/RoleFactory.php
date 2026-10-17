<?php

namespace Database\Factories;

use App\Models\Role;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = app(Generator::class);

        return [
            'name' => $faker->unique()->slug(2),
            'display_name' => $faker->words(2, true),
            'description' => $faker->optional()->sentence(),
            'guard_name' => 'web',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the role is inactive.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Indicate that the role is active.
     */
    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    /**
     * Sync the given permissions to the role after creation.
     *
     * @param  array<string>  $permissionNames
     */
    public function withPermissions(array $permissionNames): static
    {
        return $this->afterCreating(function (Role $role) use ($permissionNames): void {
            $role->syncPermissions($permissionNames);
        });
    }
}
