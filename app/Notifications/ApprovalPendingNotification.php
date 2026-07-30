<?php

namespace App\Notifications;

use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApprovalPendingNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $subjectSlug,
        private readonly int $subjectId,
        private readonly string $title,
        private readonly string $stageLabel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $settings = SystemSetting::notification();
        $categoryEnabled = match ($this->subjectSlug) {
            'loans' => $settings['loanApprovalAlerts'],
            'benefits' => $settings['benefitApprovalAlerts'],
            default => $settings['userAccountAlerts'],
        };

        if (! $categoryEnabled) {
            return [];
        }

        $channels = [];
        if ($settings['inAppNotifications']) {
            $channels[] = 'database';
        }
        if ($settings['emailNotifications'] && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->full_name},")
            ->line("{$this->title} is waiting for {$this->stageLabel}.")
            ->action('Open approval', rtrim(config('app.frontend_url', config('app.url')), '/')."/approvals/{$this->subjectSlug}/{$this->subjectId}")
            ->line('You received this message because approval email notifications are enabled in System Settings.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'approval_pending',
            'subjectType' => $this->subjectSlug,
            'subjectId' => (string) $this->subjectId,
            'title' => $this->title,
            'message' => "Waiting for {$this->stageLabel}.",
            'link' => "/approvals/{$this->subjectSlug}/{$this->subjectId}",
        ];
    }
}
