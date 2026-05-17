<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskFlowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly ?int $taskId = null,
        private readonly ?int $projectId = null,
        private readonly ?string $actionUrl = null,
        private readonly bool $sendMail = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->sendMail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message);

        if ($this->actionUrl) {
            $mail->action('Open in Friday', url($this->actionUrl));
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'task_id' => $this->taskId,
            'project_id' => $this->projectId,
            'action_url' => $this->actionUrl,
        ];
    }
}
