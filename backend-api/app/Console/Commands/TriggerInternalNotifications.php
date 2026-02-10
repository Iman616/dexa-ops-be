<?php
// app/Console/Commands/TriggerInternalNotifications.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;

class TriggerInternalNotifications extends Command
{
    protected $signature = 'notifications:trigger';
    protected $description = 'Trigger sending internal notifications';

    public function __construct(protected NotificationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->service->triggerNotifications();
        $this->info("Triggered {$count} internal notifications.");

        return Command::SUCCESS;
    }
}
