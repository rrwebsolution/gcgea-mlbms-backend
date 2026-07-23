<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ApprovalPendingNotification extends Notification implements ShouldQueue
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
        return ['database'];
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
