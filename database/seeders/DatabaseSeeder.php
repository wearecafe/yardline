<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The demo yard is idempotent: skipped if it already exists, so this is
     * safe to run on every deploy.
     */
    public function run(): void
    {
        $this->call(DemoYardSeeder::class);
    }
}
