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
        // Generate reminders every day at 08:00
        $schedule->command('agent-payments:generate-reminders')->dailyAt('08:00');

        // Trigger sending reminders every day at 08:05
        $schedule->command('agent-payments:trigger-reminders')->dailyAt('08:05');


          $schedule->command('finance:generate-notifications')->dailyAt('08:00');
    $schedule->command('notifications:trigger')->dailyAt('08:05');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
