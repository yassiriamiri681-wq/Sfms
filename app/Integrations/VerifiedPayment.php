<?php
declare(strict_types=1);
namespace App\Integrations;
final readonly class VerifiedPayment {
    public function __construct(public string $eventId, public int $schoolId, public int $invoiceId, public int $amountMinor, public string $currency, public string $reference) {}
}
