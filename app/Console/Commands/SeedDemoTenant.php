<?php

namespace App\Console\Commands;

use Database\Seeders\DemoTenantSeeder;
use Illuminate\Console\Command;

class SeedDemoTenant extends Command
{
    protected $signature = 'demo:seed {--fresh : Wipe the database and rebuild the demo tenant (destructive)}';

    protected $description = 'Seed the deterministic demo tenant (2 branches, inventory, users, open business dates)';

    public function handle(): int
    {
        if ($this->option('fresh') && ! $this->confirm('Wipe the database and rebuild the demo tenant?', false)) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
        }

        /** @var DemoTenantSeeder $seeder */
        $seeder = app(DemoTenantSeeder::class);
        $seeder->run();

        foreach ($seeder->counts() as $label => $count) {
            $this->line("  {$label}: {$count}");
        }

        $this->info('Demo tenant ready. Log in with admin@demo.hotel / password.');

        return self::SUCCESS;
    }
}
