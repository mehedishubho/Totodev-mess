<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        // Lock meals daily at 10 AM
        $schedule->job(new \App\Jobs\LockMealsJob())->dailyAt('10:00');

        // Generate monthly bills on 1st of each month
        $schedule->command('bills:generate')->monthlyOn(1, '00:00');

        // Daily backup
        $schedule->command('backup:run')->dailyAt('02:00');

        // Clean up old logs weekly
        $schedule->command('log:clear')->weekly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
