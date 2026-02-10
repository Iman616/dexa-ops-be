<?php
// app/Console/Commands/TriggerAgentPaymentReminders.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AgentPaymentService;

class TriggerAgentPaymentReminders extends Command
{
    protected $signature = 'agent-payments:trigger-reminders';
    protected $description = 'Trigger sending reminders for agent payments';

    protected $service;

    public function __construct(AgentPaymentService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $count = $this->service->triggerReminders();
        $this->info("Triggered {$count} reminders for agent payments.");

        return Command::SUCCESS;
    }
}
