<?php
declare(strict_types=1);
// The existing fixture installer refuses populated or non-test databases.
ob_start();
require __DIR__ . '/integration.php';
use App\Core\{Auth, DB};
use App\Services\{Batches, Catalog, Students, Queries, Finance};
$baselineOutput = ob_get_clean();
ob_start();
$batchChecks = 0;
function batchCheck(bool $ok, string $label): void { global $batchChecks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $batchChecks++; }
function batchReject(callable $fn, string $label): void { try { $fn(); } catch (DomainException) { batchCheck(true, $label); return; } throw new RuntimeException('FAIL: ' . $label); }
function counts(): array { return array_map(fn($t) => (int)DB::one("SELECT COUNT(*) n FROM $t")['n'], ['invoices','invoice_items','audit_logs','notification_outbox','student_academic_history']); }
try {
    $fixture = json_decode(file_get_contents(ROOT . '/tests/.runtime-fixture.json'), true);
    Auth::login('super@example.test', $fixture['password']); $_SESSION['school_id'] = $fixture['school'];
    $year = (int)DB::one('SELECT id FROM academic_years ORDER BY id LIMIT 1')['id'];
    $class = (int)DB::one("SELECT id FROM classes WHERE school_id=? AND name='Form 1'", [Auth::schoolId()])['id'];
    $target = (int)DB::one("SELECT id FROM classes WHERE school_id=? AND name='Form 2'", [Auth::schoolId()])['id'];
    $term = (int)DB::one('SELECT id FROM terms WHERE school_id=? ORDER BY id LIMIT 1', [Auth::schoolId()])['id'];
    $day = (int)DB::one('SELECT id FROM student_types WHERE school_id=? AND requires_boarding=0', [Auth::schoolId()])['id'];
    $start = date('Y') . '-01-01';
    $new = Students::save(['admission_number'=>'BATCH-DAY','first_name'=>'Batch','last_name'=>'Day','gender'=>'Female','date_of_birth'=>'2012-01-01','admission_date'=>$start,'status'=>'Active','academic_year_id'=>$year,'class_id'=>$class,'student_type_id'=>$day,'starts_on'=>$start]);
    $input = ['source_year_id'=>$year,'source_class_id'=>$class,'term_id'=>$term,'issued_on'=>date('Y-m-d'),'due_on'=>date('Y-m-d')];
    $before = counts(); $preview = Batches::preview('billing', $input);
    batchCheck(counts() === $before, 'Preview is read-only');
    batchCheck($preview['ready_count'] === 2 && count($preview['rows']) === 3, 'Eligible class selected and existing invoice skipped');
    batchCheck($preview['total'] === 270000000, 'Different day and boarding fees retained');
    batchCheck($preview['blocked_count'] === 0, 'Configured class can proceed');
    $_SESSION['school_id'] = 2; batchReject(fn()=>Batches::apply($preview), 'Review cannot cross schools'); $_SESSION['school_id'] = $fixture['school'];
    $expired = $preview; $expired['created_at'] = time()-901; batchReject(fn()=>Batches::apply($expired), 'Expired review rejected');
    $fee = DB::one('SELECT i.* FROM fee_structure_items i JOIN fee_structures f ON f.school_id=i.school_id AND f.id=i.fee_structure_id WHERE f.school_id=? AND f.student_type_id=?', [Auth::schoolId(), $day]);
    Catalog::item(['fee_structure_id'=>$fee['fee_structure_id'],'fee_type_id'=>$fee['fee_type_id'],'amount'=>'1250000']);
    batchReject(fn()=>Batches::apply($preview), 'Changed fee invalidates preview');
    Catalog::item(['fee_structure_id'=>$fee['fee_structure_id'],'fee_type_id'=>$fee['fee_type_id'],'amount'=>'1200000']);
    $missing = DB::insert('student_types', ['school_id'=>Auth::schoolId(),'name'=>'Unconfigured category']);
    $badStudent = Students::save(['admission_number'=>'BATCH-MISSING','first_name'=>'Missing','last_name'=>'Fees','gender'=>'Male','date_of_birth'=>'2012-01-01','admission_date'=>$start,'status'=>'Active','academic_year_id'=>$year,'class_id'=>$class,'student_type_id'=>$missing,'starts_on'=>$start]);
    $blocked = Batches::preview('billing', $input); batchCheck($blocked['blocked_count'] === 1, 'Missing category fee identified');
    $before = counts(); batchReject(fn()=>Batches::apply($blocked), 'Blocked batch rejected'); batchCheck(counts() === $before, 'Blocked batch makes no changes');
    DB::run("UPDATE students SET status='Suspended' WHERE school_id=? AND id=?", [Auth::schoolId(),$badStudent]);
    $preview = Batches::preview('billing', $input); $before = counts();
    try { DB::transaction(function () use ($preview) { Batches::apply($preview); throw new DomainException('Simulated final failure'); }); } catch (DomainException) {}
    batchCheck(counts() === $before, 'Outer failure rolls back invoices, items, outbox, audit and enrollments');
    $result = Batches::apply($preview); batchCheck($result['processed'] === 2 && $result['skipped'] === 1, 'Batch posts exactly the reviewed invoices');
    $before = counts(); batchReject(fn()=>Batches::apply($preview), 'Replayed review rejected'); batchCheck(counts() === $before, 'Replay creates no duplicates');
    $again = Batches::preview('billing', $input); batchCheck($again['ready_count'] === 0, 'Already billed class has no remaining work');
    batchReject(fn()=>Batches::apply($again), 'Empty batch cannot be posted');
    Catalog::save(['table'=>'academic_years','name'=>'Next batch year','starts_on'=>(date('Y')+1).'-01-01','ends_on'=>(date('Y')+1).'-12-31']);
    $newYear = (int)DB::one('SELECT MAX(id) id FROM academic_years')['id'];
    $promotion = ['source_year_id'=>$year,'source_class_id'=>$class,'target_year_id'=>$newYear,'target_class_id'=>$target,'starts_on'=>(date('Y')+1).'-01-01'];
    $p = Batches::preview('promotion', $promotion); batchCheck($p['ready_count'] === 3, 'Promotion excludes suspended student');
    $before = counts();
    try { DB::transaction(function () use ($p) { Batches::apply($p); throw new DomainException('Rollback promotion'); }); } catch (DomainException) {}
    batchCheck(counts() === $before, 'Promotion and its audit history roll back together');
    $totals = DB::all('SELECT id,total,paid,balance FROM invoice_balances ORDER BY id');
    $result = Batches::apply($p); batchCheck($result['processed'] === 3, 'Reviewed promotion applies');
    batchCheck(DB::all('SELECT id,total,paid,balance FROM invoice_balances ORDER BY id') === $totals, 'Promotion preserves all historical finances');
    $enrollment = DB::one('SELECT * FROM student_academic_history WHERE student_id=? AND is_current=1', [$new]);
    batchCheck((int)$enrollment['student_type_id'] === $day && (int)$enrollment['academic_year_id'] === $newYear, 'Student type retained in new year');
    batchCheck((int)DB::one('SELECT COUNT(*) n FROM student_academic_history WHERE student_id=?', [$new])['n'] === 2, 'Previous enrollment preserved');
    batchReject(fn()=>Batches::preview('billing', $input+['source_stream_id'=>999999]), 'Forged source filter rejected');
    Auth::login('teacher@example.test', $fixture['password']);
    batchReject(fn()=>Batches::preview('billing', $input), 'Teacher denied batch billing');
    batchReject(fn()=>Batches::preview('promotion', $promotion), 'Teacher denied batch promotion');
    ob_end_clean(); echo $baselineOutput . "PASS: $batchChecks batch workflow checks\n";
} catch (Throwable $error) { ob_end_clean(); fwrite(STDERR, $error . "\n"); exit(1); }
