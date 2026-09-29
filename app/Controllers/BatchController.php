<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth, Input};
use App\Services\Batches;

final class BatchController
{
    public static function show(Application $app): void
    {
        $kind = Input::choice($_GET, 'kind', ['billing', 'promotion']);
        Auth::require($kind === 'billing' ? 'invoices.write' : 'students.write');
        $review = null; $token = (string)($_GET['review'] ?? '');
        if ($token !== '') {
            $review = $_SESSION['batch_reviews'][$token] ?? null;
            if (!$review || $review['kind'] !== $kind || $review['school_id'] !== Auth::schoolId() || $review['user_id'] !== (int)Auth::user()['id']) throw new \DomainException('Review not found in this workspace. Create a new preview.');
            if ($review['created_at'] < time() - 900) throw new \DomainException('This review expired. Create a new preview.');
        }
        $app->render('batch', $kind === 'billing' ? 'Bulk invoice generation' : 'Bulk student promotion', compact('kind', 'review', 'token'));
    }

    public static function preview(): never
    {
        $kind = Input::choice($_POST, 'kind', ['billing', 'promotion']);
        $review = Batches::preview($kind, $_POST);
        foreach ($_SESSION['batch_reviews'] ?? [] as $key => $old) if ($old['created_at'] < time() - 900) unset($_SESSION['batch_reviews'][$key]);
        if (count($_SESSION['batch_reviews'] ?? []) >= 5) array_shift($_SESSION['batch_reviews']);
        $token = bin2hex(random_bytes(24));
        $_SESSION['batch_reviews'][$token] = $review;
        redirect('batch', ['kind' => $kind, 'review' => $token]);
    }

    public static function apply(): never
    {
        $token = Input::text($_POST, 'review', 48);
        $review = $_SESSION['batch_reviews'][$token] ?? throw new \DomainException('This review was already processed or has expired. Create a new preview.');
        $result = Batches::apply($review);
        unset($_SESSION['batch_reviews'][$token]);
        $_SESSION['flash'] = $result['processed'] . ($review['kind'] === 'billing' ? ' invoices generated. ' : ' students promoted. ') . $result['skipped'] . ' already processed records skipped. Previous financial and academic history retained.';
        redirect($review['kind'] === 'billing' ? 'invoices' : 'students');
    }
}
