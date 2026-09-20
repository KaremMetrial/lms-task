<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * The default seed is the demonstration dataset — small, deterministic, and
     * covering every state the system can reach.
     *
     * For volume, run the scale seeder explicitly:
     *
     *   php artisan db:seed --class=ScaleSeeder
     *
     * It is not wired in here because it writes millions of rows, and that should
     * never be something `migrate --seed` does by surprise.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
