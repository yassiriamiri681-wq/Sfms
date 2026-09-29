<?php
declare(strict_types=1);
namespace App\Integrations;
interface NotificationChannel {
    public function deliver(int $schoolId, string $eventId, string $recipient, string $message): void;
}
