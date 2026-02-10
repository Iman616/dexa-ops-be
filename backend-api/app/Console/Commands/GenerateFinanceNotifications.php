<?php
// app/Console/Commands/GenerateFinanceNotifications.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotificationService;

class GenerateFinanceNotifications extends Command
{
    protected $signature = 'finance:generate-notifications';
    protected $description = 'Generate internal notifications for finance (agent payments, bank guarantees, etc)';

    public function __construct(protected NotificationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $countPayment = $this->service->generateAgentPaymentNotifications();
        $countBG = $this->service->generateBankGuaranteeNotifications(30);

        $this->info("Generated {$countPayment} payment notifications, {$countBG} bank guarantee notifications.");

        return Command::SUCCESS;
    }
}
