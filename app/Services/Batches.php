<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth, DB, Input};

/** Reviewed batches are limited to 200 students and commit as one transaction. */
final class Batches
{
    public static function preview(string $kind, array $input): array
    {
        self::authorize($kind);
        $data = self::validate($kind, $input);
        $students = self::cohort($data);
        if (!$students) throw new \DomainException('No active students match this academic year, class and optional filters.');
        if (count($students) > 200) throw new \DomainException('A batch can contain at most 200 students. Narrow the selection by stream or student type.');
        $rows = self::review($kind, $data, $students);
        $ready = array_filter($rows, fn(array $row): bool => $row['state'] === 'Ready');
        $blocked = array_filter($rows, fn(array $row): bool => $row['state'] === 'Blocked');
        return [
            'kind' => $kind, 'school_id' => Auth::schoolId(), 'user_id' => (int)Auth::user()['id'],
            'data' => $data, 'rows' => $rows, 'created_at' => time(),
            'fingerprint' => self::fingerprint($rows), 'ready_count' => count($ready),
            'blocked_count' => count($blocked), 'total' => array_sum(array_column($ready, 'amount')),
        ];
    }

    public static function apply(array $preview): array
    {
        $kind = $preview['kind'] ?? '';
        self::authorize($kind);
        if ((int)($preview['school_id'] ?? 0) !== Auth::schoolId() || (int)($preview['user_id'] ?? 0) !== (int)Auth::user()['id']) {
            throw new \DomainException('This review belongs to another school or user. Create a new preview.');
        }
        if ((int)($preview['created_at'] ?? 0) < time() - 900) throw new \DomainException('This review has expired. Preview the batch again.');
        return DB::transaction(function () use ($preview, $kind): array {
            $sid = Auth::schoolId();
            DB::one('SELECT id FROM schools WHERE id=? FOR UPDATE', [$sid]);
            $data = self::validate($kind, $preview['data']);
            $ids = array_column($preview['rows'], 'student_id');
            if (!$ids || count($ids) > 200) throw new \DomainException('Invalid batch size.');
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) DB::one('SELECT id FROM students WHERE school_id=? AND id=? FOR UPDATE', [$sid, $id]);
            if ($kind === 'billing') {
                // Fee editors also lock the parent structure before changing its items.
                DB::all('SELECT id FROM fee_structures WHERE school_id=? AND term_id=? AND class_id=? ORDER BY id FOR UPDATE', [$sid, $data['term_id'], $data['source_class_id']]);
            }
            $students = self::cohort($data);
            $rows = self::review($kind, $data, $students);
            if (!hash_equals((string)$preview['fingerprint'], self::fingerprint($rows))) throw new \DomainException('Students, enrollments or fees changed after your review. Nothing was posted. Create a new preview.');
            if (array_filter($rows, fn(array $r): bool => $r['state'] === 'Blocked')) throw new \DomainException('Resolve every blocked student before applying this batch.');
            $ready = array_filter($rows, fn(array $r): bool => $r['state'] === 'Ready');
            if (!$ready) throw new \DomainException('There are no students ready to process.');
            $result = ['processed' => 0, 'skipped' => count($rows) - count($ready), 'invoice_ids' => []];
            foreach ($ready as $row) {
                if ($kind === 'billing') {
                    $result['invoice_ids'][] = Finance::invoice([
                        'student_id' => $row['student_id'], 'term_id' => $data['term_id'],
                        'issued_on' => $data['issued_on'], 'due_on' => $data['due_on'],
                    ]);
                } else {
                    Students::enroll((int)$row['student_id'], [
                        'academic_year_id' => $data['target_year_id'], 'class_id' => $data['target_class_id'],
                        'stream_id' => $data['target_stream_id'], 'student_type_id' => $row['student_type_id'],
                        'starts_on' => $data['starts_on'],
                    ]);
                }
                $result['processed']++;
            }
            Auth::audit('batch.' . $kind, 'schools', $sid, json_encode(['processed' => $result['processed'], 'skipped' => $result['skipped'], 'students' => array_column($ready, 'student_id')], JSON_THROW_ON_ERROR));
            return $result;
        });
    }

    private static function authorize(string $kind): void
    {
        if (!in_array($kind, ['billing', 'promotion'], true)) throw new \DomainException('Unknown batch operation.');
        Auth::require($kind === 'billing' ? 'invoices.write' : 'students.write');
    }

    private static function validate(string $kind, array $input): array
    {
        $data = [];
        foreach (['source_year_id' => 'academic_years', 'source_class_id' => 'classes'] as $key => $table) {
            $data[$key] = Input::id($input, $key); Auth::owned($table, $data[$key]);
        }
        foreach (['source_stream_id' => 'streams', 'source_type_id' => 'student_types'] as $key => $table) {
            $data[$key] = empty($input[$key]) ? 0 : Input::id($input, $key);
            if ($data[$key]) Auth::owned($table, $data[$key]);
        }
        if ($data['source_stream_id'] && (int)Auth::owned('streams', $data['source_stream_id'])['class_id'] !== $data['source_class_id']) throw new \DomainException('Source stream must belong to the source class.');
        if ($kind === 'billing') {
            $data['term_id'] = Input::id($input, 'term_id');
            if ((int)Auth::owned('terms', $data['term_id'])['academic_year_id'] !== $data['source_year_id']) throw new \DomainException('Billing term must belong to the source academic year.');
            $data['issued_on'] = Input::date($input, 'issued_on');
            $data['due_on'] = Input::date($input, 'due_on');
            if ($data['due_on'] < $data['issued_on']) throw new \DomainException('Due date cannot precede issue date.');
        } else {
            foreach (['target_year_id' => 'academic_years', 'target_class_id' => 'classes'] as $key => $table) {
                $data[$key] = Input::id($input, $key); Auth::owned($table, $data[$key]);
            }
            $data['target_stream_id'] = empty($input['target_stream_id']) ? 0 : Input::id($input, 'target_stream_id');
            if ($data['target_stream_id'] && (int)Auth::owned('streams', $data['target_stream_id'])['class_id'] !== $data['target_class_id']) throw new \DomainException('Destination stream must belong to the destination class.');
            $year = Auth::owned('academic_years', $data['target_year_id']);
            $data['starts_on'] = Input::date($input, 'starts_on');
            if ($data['starts_on'] < $year['starts_on'] || $data['starts_on'] > $year['ends_on']) throw new \DomainException('Enrollment date must fall within the destination academic year.');
        }
        return $data;
    }

    private static function cohort(array $data): array
    {
        $where = ['s.school_id=?', "s.status='Active'", 'h.is_current=1', 'h.academic_year_id=?', 'h.class_id=?'];
        $params = [Auth::schoolId(), $data['source_year_id'], $data['source_class_id']];
        foreach (['source_stream_id' => 'stream_id', 'source_type_id' => 'student_type_id'] as $key => $column) {
            if ($data[$key]) { $where[] = 'h.' . $column . '=?'; $params[] = $data[$key]; }
        }
        return DB::all('SELECT s.id student_id,s.admission_number,CONCAT_WS(\' \',s.first_name,s.last_name) student_name,h.id enrollment_id,h.student_type_id,h.stream_id,h.starts_on,t.name type_name FROM students s JOIN student_academic_history h ON h.school_id=s.school_id AND h.student_id=s.id JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id LIMIT 201', $params);
    }

    private static function review(string $kind, array $data, array $students): array
    {
        $rows = []; $sid = Auth::schoolId();
        foreach ($students as $student) {
            $row = $student + ['state' => 'Ready', 'note' => '', 'amount' => 0];
            if ($kind === 'billing') {
                $existing = DB::one('SELECT number,cancelled_at FROM invoices WHERE school_id=? AND student_id=? AND term_id=?', [$sid, $student['student_id'], $data['term_id']]);
                if ($existing) { $row['state'] = 'Skipped'; $row['note'] = 'Already billed: ' . $existing['number'] . ($existing['cancelled_at'] ? ' (cancelled)' : ''); }
                else {
                    $items = DB::all('SELECT i.fee_type_id,i.amount,t.name FROM fee_structures f JOIN fee_structure_items i ON i.school_id=f.school_id AND i.fee_structure_id=f.id JOIN fee_types t ON t.school_id=i.school_id AND t.id=i.fee_type_id WHERE f.school_id=? AND f.academic_year_id=? AND f.term_id=? AND f.class_id=? AND f.student_type_id=? ORDER BY i.fee_type_id', [$sid, $data['source_year_id'], $data['term_id'], $data['source_class_id'], $student['student_type_id']]);
                    if (!$items) { $row['state'] = 'Blocked'; $row['note'] = 'Missing or empty fee structure for this student type.'; }
                    else { $row['amount'] = array_sum(array_column($items, 'amount')); $row['fees_hash'] = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)); $row['note'] = count($items) . ' applicable fee items'; }
                }
            } elseif ($data['starts_on'] < $student['starts_on']) {
                $row['state'] = 'Blocked'; $row['note'] = 'Destination enrollment starts before the current enrollment.';
            } elseif ($data['source_year_id'] === $data['target_year_id'] && $data['source_class_id'] === $data['target_class_id'] && (int)$student['stream_id'] === $data['target_stream_id']) {
                $row['state'] = 'Skipped'; $row['note'] = 'Already in this destination enrollment.';
            } else $row['note'] = 'Student type retained; prior enrollment and financial records preserved.';
            $rows[] = $row;
        }
        return $rows;
    }

    private static function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
