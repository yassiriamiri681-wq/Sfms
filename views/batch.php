<?php
use App\Core\Auth;
$billing = $kind === 'billing';
?>
<div class="toolbar">
    <a class="text-link" href="<?= e(url($billing ? 'invoices' : 'students')) ?>">← <?= $billing ? 'Invoices' : 'Students' ?></a>
    <span class="badge neutral">Select → Review → Confirm</span>
</div>
<?php if (!$review): ?>
<section class="panel">
    <h2><?= $billing ? 'Bill a class in one operation' : 'Move a class to its next enrollment' ?></h2>
    <?php if (!$billing): ?><p class="note">The destination becomes the current enrollment as soon as you confirm. The start date is recorded for history; it does not schedule a future change.</p><?php endif ?>
    <p class="muted"><?= $billing ? 'Each active student receives the fees configured for their own student type. Existing invoices are skipped, including cancelled invoices.' : 'Day Scholar and Boarding Student types are retained. Existing invoices, payments, statements and academic history remain available.' ?> Up to 200 students per batch.</p>
    <?php startForm('batch.preview', ['kind' => $kind]); ?>
    <h3 class="full">1. Select the current enrollment</h3>
    <?php
    selectField('source_year_id', 'Current academic year', 'academic_years');
    selectField('source_class_id', 'Current class', 'classes');
    selectField('source_stream_id', 'Current stream · optional', 'streams', '', false);
    selectField('source_type_id', 'Student type · optional', 'student_types', '', false);
    ?>
    <h3 class="full">2. <?= $billing ? 'Invoice details' : 'Destination enrollment' ?></h3>
    <?php if ($billing):
        selectField('term_id', 'Billing term', 'terms');
        field('issued_on', 'Issue date', 'date', date('Y-m-d'));
        field('due_on', 'Due date', 'date', date('Y-m-d', strtotime('+30 days')));
    else:
        selectField('target_year_id', 'Destination academic year', 'academic_years');
        selectField('target_class_id', 'Destination class', 'classes');
        selectField('target_stream_id', 'Destination stream · optional', 'streams', '', false);
        field('starts_on', 'Enrollment start date', 'date', date('Y-m-d'));
    endif;
    endForm('Preview students'); ?>
</section>
<?php else: $data = $review['data']; ?>
<section class="panel">
    <h2>Review before <?= $billing ? 'generating invoices' : 'changing enrollment' ?></h2>
    <div class="detail-grid">
        <div><small>Source</small><strong><?= e(Auth::owned('academic_years', $data['source_year_id'])['name']) ?> · <?= e(Auth::owned('classes', $data['source_class_id'])['name']) ?></strong></div>
        <?php if ($billing): ?>
        <div><small>Billing term</small><strong><?= e(Auth::owned('terms', $data['term_id'])['name']) ?></strong></div>
        <div><small>Issue / due date</small><strong><?= e($data['issued_on'] . ' / ' . $data['due_on']) ?></strong></div>
        <?php else: ?>
        <div><small>Destination</small><strong><?= e(Auth::owned('academic_years', $data['target_year_id'])['name']) ?> · <?= e(Auth::owned('classes', $data['target_class_id'])['name']) ?></strong><span><?= $data['target_stream_id'] ? e(Auth::owned('streams', $data['target_stream_id'])['name']) : 'No stream' ?></span></div>
        <div><small>Enrollment starts</small><strong><?= e($data['starts_on']) ?></strong></div>
        <?php endif ?>
        <div><small>Ready</small><strong><?= e($review['ready_count']) ?> students</strong></div>
        <div><small>Blocked / skipped</small><strong><?= e($review['blocked_count']) ?> / <?= e(count($review['rows']) - $review['blocked_count'] - $review['ready_count']) ?></strong></div>
        <?php if ($billing): ?><div><small>Total new charges · <?= e($school['currency']) ?></small><strong><?= money($review['total']) ?></strong></div><?php endif ?>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Student</th><th>Admission</th><th>Student type</th><th>Status</th><th>Details</th><?php if ($billing): ?><th class="num">New charge</th><?php endif ?></tr></thead>
        <tbody><?php foreach ($review['rows'] as $row): ?><tr>
            <td><?= e($row['student_name']) ?></td><td><?= e($row['admission_number']) ?></td><td><?= e($row['type_name']) ?></td>
            <td><span class="badge <?= $row['state'] === 'Blocked' ? 'bad' : ($row['state'] === 'Ready' ? 'good' : 'neutral') ?>"><?= e($row['state']) ?></span></td>
            <td><?= e($row['note']) ?></td><?php if ($billing): ?><td class="num"><?= $row['state'] === 'Ready' ? money($row['amount']) : '—' ?></td><?php endif ?>
        </tr><?php endforeach ?></tbody>
    </table></div>
    <?php if ($review['blocked_count']): ?>
        <p class="flash warning">Nothing has been saved. Resolve the blocked students, then create a new preview.</p>
    <?php elseif (!$review['ready_count']): ?>
        <p class="note">Every matching student is already processed. No changes are needed.</p>
    <?php else: ?>
        <p class="note">This review expires after 15 minutes. The system checks it again before saving. Any changed student or fee information requires a new review. The whole batch succeeds or no changes are saved.</p>
        <?php startForm('batch.apply', ['review' => $token]); ?>
        <div class="form-actions"><button class="button primary" data-confirm="<?= $billing ? 'Generate the reviewed invoices?' : 'Apply the reviewed enrollment changes now?' ?>"><?= $billing ? 'Confirm & generate invoices' : 'Confirm & promote students' ?></button></div></form>
    <?php endif ?>
    <p><a class="text-link" href="<?= e(url('batch', ['kind' => $kind])) ?>">Start a new preview →</a></p>
</section>
<?php endif ?>
