<?php

namespace Database\Factories;

use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'minutes' => $this->faker->numberBetween(15, 480),
            'spent_on' => now()->toDateString(),
            'note' => null,
            'billable' => true,
        ];
    }
}
