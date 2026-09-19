<?php

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => ucfirst($name),
            'key' => CustomField::keyFrom($name),
            'type' => CustomFieldType::Text->value,
            'options' => null,
            'required' => false,
            'visible_to_client' => false,
            'position' => 0,
        ];
    }

    public function clientVisible(): static
    {
        return $this->state(['visible_to_client' => true]);
    }

    public function select(array $options): static
    {
        return $this->state([
            'type' => CustomFieldType::Select->value,
            'options' => $options,
        ]);
    }
}
