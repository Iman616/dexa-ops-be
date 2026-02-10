<?php
// app/Console/Commands/GenerateAgentPaymentReminders.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AgentPaymentService;

class GenerateAgentPaymentReminders extends Command
{
    protected $signature = 'agent-payments:generate-reminders';
    protected $description = 'Generate reminders for upcoming and overdue agent payments';

    protected $service;

    public function __construct(AgentPaymentService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        $count = $this->service->generateReminders();
        $this->info("Generated {$count} reminders for agent payments.");

        return Command::SUCCESS;
    }
}
