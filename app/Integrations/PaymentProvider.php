<?php
declare(strict_types=1);
namespace App\Integrations;
interface PaymentProvider {
    /** Implementations must verify signatures and return a provider-unique event ID. */
    public function verifyWebhook(string $body, array $headers): VerifiedPayment;
    public function checkout(int $schoolId, int $invoiceId, int $amountMinor, string $currency): string;
}
