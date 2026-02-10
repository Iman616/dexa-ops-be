<?php
// app/Services/NotificationService.php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\AgentPayment;
use App\Models\TenderProjectDetail;
use Illuminate\Support\Carbon;

class NotificationService
{
    /**
     * Create notification (generic)
     */
    public function create(array $data): InternalNotification
    {
        return InternalNotification::create([
            'user_id' => $data['user_id'] ?? null,
            'channel' => $data['channel'] ?? 'system',
            'type' => $data['type'] ?? null,
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'scheduled_at' => $data['scheduled_at'] ?? now(),
            'status' => 'pending',
            'meta' => $data['meta'] ?? null,
        ]);
    }

    /**
     * Generate overdue / due soon notifications for agent payments
     * (dipanggil dari cron atau langsung dari AgentPaymentService.generateReminders)
     */
    public function generateAgentPaymentNotifications(): int
    {
        $today = Carbon::today();
        $soonDate = $today->copy()->addDays(7);

        // Due soon
        $dueSoon = AgentPayment::whereIn('status', ['pending', 'partial'])
            ->whereBetween('due_date', [$today, $soonDate])
            ->get();

        $count = 0;

        foreach ($dueSoon as $payment) {
            $exists = InternalNotification::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('type', 'agent_payment_due_soon')
                ->whereDate('scheduled_at', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            InternalNotification::create([
                'user_id' => null, // nanti bisa diisi user finance
                'channel' => 'system',
                'type' => 'agent_payment_due_soon',
                'title' => "Pembayaran agent akan jatuh tempo",
                'message' => "Tagihan ke {$payment->supplier->supplier_name} jatuh tempo {$payment->due_date}, sisa: {$payment->outstanding_amount}.",
                'reference_type' => 'agent_payment',
                'reference_id' => $payment->agent_payment_id,
                'scheduled_at' => $today->copy()->setTime(8, 0),
                'status' => 'pending',
                'meta' => [
                    'supplier_name' => $payment->supplier->supplier_name,
                    'payment_number' => $payment->payment_number,
                    'due_date' => $payment->due_date,
                    'outstanding_amount' => $payment->outstanding_amount,
                ],
            ]);

            $count++;
        }

        // Overdue
        $overdue = AgentPayment::whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', $today)
            ->get();

        foreach ($overdue as $payment) {
            $exists = InternalNotification::where('reference_type', 'agent_payment')
                ->where('reference_id', $payment->agent_payment_id)
                ->where('type', 'agent_payment_overdue')
                ->whereDate('scheduled_at', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            InternalNotification::create([
                'user_id' => null,
                'channel' => 'system',
                'type' => 'agent_payment_overdue',
                'title' => "PEMBAYARAN AGENT TERLAMBAT",
                'message' => "Tagihan ke {$payment->supplier->supplier_name} sudah lewat jatuh tempo ({$payment->due_date}), sisa: {$payment->outstanding_amount}.",
                'reference_type' => 'agent_payment',
                'reference_id' => $payment->agent_payment_id,
                'scheduled_at' => $today->copy()->setTime(8, 5),
                'status' => 'pending',
                'meta' => [
                    'supplier_name' => $payment->supplier->supplier_name,
                    'payment_number' => $payment->payment_number,
                    'due_date' => $payment->due_date,
                    'outstanding_amount' => $payment->outstanding_amount,
                ],
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Generate bank guarantee expiring notifications
     */
    public function generateBankGuaranteeNotifications(int $days = 30): int
    {
        $today = Carbon::today();
        $limit = $today->copy()->addDays($days);

        $projects = TenderProjectDetail::whereNotNull('bank_guarantee_number')
            ->whereBetween('bank_guarantee_end_date', [$today, $limit])
            ->with(['purchaseOrder.customer'])
            ->get();

        $count = 0;

        foreach ($projects as $project) {
            $exists = InternalNotification::where('reference_type', 'tender_project_detail')
                ->where('reference_id', $project->tender_project_detail_id)
                ->where('type', 'bank_guarantee_expiring')
                ->whereDate('scheduled_at', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            InternalNotification::create([
                'user_id' => null,
                'channel' => 'system',
                'type' => 'bank_guarantee_expiring',
                'title' => "Bank Garansi akan habis",
                'message' => "BG {$project->bank_guarantee_number} untuk project {$project->project_name} (customer {$project->purchaseOrder->customer->customer_name}) akan habis pada {$project->bank_guarantee_end_date?->format('d-m-Y')}.",
                'reference_type' => 'tender_project_detail',
                'reference_id' => $project->tender_project_detail_id,
                'scheduled_at' => $today->copy()->setTime(8, 10),
                'status' => 'pending',
                'meta' => [
                    'tender_number' => $project->tender_number,
                    'project_name' => $project->project_name,
                    'bg_number' => $project->bank_guarantee_number,
                    'bg_end_date' => optional($project->bank_guarantee_end_date)->toDateString(),
                ],
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Trigger sending (untuk channel = system: cukup markAsSent)
     * Nanti bisa dikembangkan ke email/whatsapp.
     */
    public function triggerNotifications(): int
    {
        $now = Carbon::now();

        $notifications = InternalNotification::where('status', 'pending')
            ->where('scheduled_at', '<=', $now)
            ->get();

        foreach ($notifications as $notification) {
            // TODO: kalau nanti pakai email, di-handle di sini
            $notification->markAsSent();
        }

        return $notifications->count();
    }

    /**
     * Get unread notifications untuk user (untuk UI bell icon)
     */
    public function getUnreadForUser(int $userId, int $limit = 10)
    {
        return InternalNotification::where(function ($q) use ($userId) {
                $q->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('status', 'sent')
            ->orderBy('sent_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function markAsRead(int $notificationId, int $userId): void
    {
        $notification = InternalNotification::where('notification_id', $notificationId)
            ->where(function ($q) use ($userId) {
                $q->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->firstOrFail();

        $notification->markAsRead();
    }

    public function markAllAsRead(int $userId): int
    {
        return InternalNotification::where(function ($q) use ($userId) {
                $q->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->update([
                'status' => 'read',
                'read_at' => now(),
            ]);
    }
}
