<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input,Money};
final class Finance {
    public static function status(array $i): string { return $i['cancelled_at'] ? 'Cancelled' : ((int)$i['balance']===0 ? 'Paid' : ($i['due_on'] < date('Y-m-d') ? 'Overdue' : ((int)$i['paid']>0 ? 'Partially Paid' : 'Unpaid'))); }
    public static function balance(int $id): array { return DB::one('SELECT * FROM invoice_balances WHERE school_id=? AND id=?',[Auth::schoolId(),$id]) ?? throw new \DomainException('Invoice not found.'); }
    private static function lock(int $id): array {
        $i=DB::one('SELECT * FROM invoices WHERE school_id=? AND id=? FOR UPDATE',[Auth::schoolId(),$id]);
        if (!$i || $i['cancelled_at']) throw new \DomainException('Invoice not found or cancelled.'); return $i;
    }
    public static function invoice(array $data): int {
        Auth::require('invoices.write'); $sid=Auth::schoolId(); $student=Input::id($data,'student_id');
        return DB::transaction(function () use($data,$sid,$student) {
            DB::one('SELECT id FROM schools WHERE id=? FOR UPDATE',[$sid]);
            $s=DB::one('SELECT * FROM students WHERE school_id=? AND id=? FOR UPDATE',[$sid,$student]);
            if (!$s || $s['status']!=='Active') throw new \DomainException('Select an active student.');
            $h=DB::one('SELECT * FROM student_academic_history WHERE school_id=? AND student_id=? AND is_current=1',[$sid,$student]);
            if (!$h) throw new \DomainException('Student needs a current enrollment.');
            $term=Auth::owned('terms',Input::id($data,'term_id'));
            if ($term['academic_year_id']!==$h['academic_year_id']) throw new \DomainException('Term must belong to the student’s academic year.');
            $structure=DB::one('SELECT * FROM fee_structures WHERE school_id=? AND academic_year_id=? AND term_id=? AND class_id=? AND student_type_id=? FOR UPDATE',[$sid,$h['academic_year_id'],$term['id'],$h['class_id'],$h['student_type_id']]);
            if (!$structure) throw new \DomainException('No fee structure matches this student’s year, term, class and student type.');
            $items=DB::all('SELECT f.*,t.name FROM fee_structure_items f JOIN fee_types t ON t.school_id=f.school_id AND t.id=f.fee_type_id WHERE f.school_id=? AND f.fee_structure_id=?',[$sid,$structure['id']]);
            if (!$items) throw new \DomainException('Add fee items before generating invoices.');
            $issued=Input::date($data,'issued_on'); $due=Input::date($data,'due_on'); if($due<$issued) throw new \DomainException('Due date cannot precede issue date.');
            $id=DB::insert('invoices',['school_id'=>$sid,'student_id'=>$student,'enrollment_id'=>$h['id'],'term_id'=>$term['id'],'issued_on'=>$issued,'due_on'=>$due,'created_by'=>Auth::user()['id']]);
            $school=DB::one('SELECT * FROM schools WHERE id=?',[$sid]);
            DB::run('UPDATE invoices SET number=? WHERE school_id=? AND id=?',[$school['invoice_prefix'].'-'.str_pad((string)$id,7,'0',STR_PAD_LEFT),$sid,$id]);
            foreach($items as $item) DB::insert('invoice_items',['school_id'=>$sid,'invoice_id'=>$id,'fee_type_id'=>$item['fee_type_id'],'description'=>$item['name'],'amount'=>$item['amount']]);
            Auth::audit('invoice.created','invoices',$id); self::event('invoice.created',['invoice_id'=>$id,'student_id'=>$student]); return $id;
        });
    }
    public static function payment(array $data): int {
        Auth::require('payments.write'); $sid=Auth::schoolId(); $invoice=Input::id($data,'invoice_id'); $amount=Money::parse(Input::text($data,'amount'));
        if($amount<=0) throw new \DomainException('Payment must be positive.');
        $key=Input::text($data,'request_key',64); if(!preg_match('/^[a-f0-9]{32,64}$/',$key)) throw new \DomainException('Invalid payment request.');
        return DB::transaction(function () use($data,$sid,$invoice,$amount,$key) {
            $i=self::lock($invoice);
            $existing=DB::one('SELECT p.id,p.amount,a.invoice_id FROM payments p JOIN payment_allocations a ON a.school_id=p.school_id AND a.payment_id=p.id WHERE p.school_id=? AND p.request_key=?',[$sid,$key]);
            if($existing) { if((int)$existing['invoice_id']!==$invoice || (int)$existing['amount']!==$amount) throw new \DomainException('Request key already used for another payment.'); return (int)$existing['id']; }
            $b=self::balance($invoice); if($amount>(int)$b['balance']) throw new \DomainException('Payment exceeds the outstanding invoice balance.');
            $date=Input::date($data,'paid_on'); if($date>date('Y-m-d') || $date<$i['issued_on']) throw new \DomainException('Payment date must be between invoice issue date and today.');
            $method=Input::choice($data,'method',['Cash','Bank','Mobile Money','Cheque','Other']);
            $reference=Input::text($data,'reference',190,$method!=='Cash');
            $id=DB::insert('payments',['school_id'=>$sid,'student_id'=>$i['student_id'],'amount'=>$amount,'paid_on'=>$date,'method'=>$method,'reference'=>$reference ?: null,'notes'=>Input::text($data,'notes',500,false),'received_by'=>Auth::user()['id'],'request_key'=>$key]);
            DB::insert('payment_allocations',['school_id'=>$sid,'payment_id'=>$id,'invoice_id'=>$invoice,'amount'=>$amount]);
            $rid=DB::insert('receipts',['school_id'=>$sid,'payment_id'=>$id,'balance_after'=>(int)$b['balance']-$amount]);
            $school=DB::one('SELECT receipt_prefix FROM schools WHERE id=?',[$sid]);
            DB::run('UPDATE receipts SET number=? WHERE school_id=? AND id=?',[$school['receipt_prefix'].'-'.str_pad((string)$rid,7,'0',STR_PAD_LEFT),$sid,$rid]);
            Auth::audit('payment.recorded','payments',$id); Auth::audit('receipt.generated','receipts',$rid); self::event('payment.recorded',['payment_id'=>$id,'student_id'=>$i['student_id']]); return $id;
        });
    }
    public static function adjust(array $data): void {
        Auth::require('invoices.write'); $id=Input::id($data,'invoice_id');
        DB::transaction(function () use($data,$id) {
            self::lock($id); $b=self::balance($id); $kind=Input::choice($data,'kind',['Additional charge','Discount','Penalty','Adjustment','Credit']); $amount=Money::parse(Input::text($data,'amount'),true);
            if(in_array($kind,['Discount','Credit'],true)) $amount=-abs($amount);
            if(in_array($kind,['Additional charge','Penalty'],true) && $amount<=0) throw new \DomainException('This charge must be positive.');
            if(!$amount || (int)$b['balance']+$amount<0) throw new \DomainException('Adjustment must be nonzero and cannot create an overpayment.');
            $aid=DB::insert('adjustments',['school_id'=>Auth::schoolId(),'invoice_id'=>$id,'kind'=>$kind,'amount'=>$amount,'reason'=>Input::text($data,'reason',500),'posted_on'=>date('Y-m-d'),'created_by'=>Auth::user()['id']]); Auth::audit('invoice.adjusted','adjustments',$aid);
        });
    }
    public static function reverse(array $data): void {
        Auth::require('payments.reverse'); $id=Input::id($data,'payment_id');
        DB::transaction(function () use($data,$id) {
            $p=Auth::owned('payments',$id); $a=DB::one('SELECT * FROM payment_allocations WHERE school_id=? AND payment_id=?',[Auth::schoolId(),$id]); self::lock((int)$a['invoice_id']);
            $p=DB::one('SELECT * FROM payments WHERE school_id=? AND id=? FOR UPDATE',[Auth::schoolId(),$id]); if($p['reversed_at']) throw new \DomainException('Payment already reversed.');
            $reason=Input::text($data,'reason',500);
            DB::run('UPDATE payments SET reversed_at=NOW(),reversal_reason=?,reversed_by=? WHERE school_id=? AND id=?',[$reason,Auth::user()['id'],Auth::schoolId(),$id]); Auth::audit('payment.reversed','payments',$id,$reason);
        });
    }
    public static function cancel(array $data): void {
        Auth::require('invoices.write'); $id=Input::id($data,'invoice_id'); DB::transaction(function () use($data,$id) { self::lock($id); if((int)self::balance($id)['paid']>0) throw new \DomainException('Reverse payments before cancellation.'); $reason=Input::text($data,'reason',500); DB::run('UPDATE invoices SET cancelled_at=NOW(),cancellation_reason=? WHERE school_id=? AND id=?',[$reason,Auth::schoolId(),$id]); Auth::audit('invoice.cancelled','invoices',$id,$reason); });
    }
    private static function event(string $type,array $payload): void { DB::insert('notification_outbox',['school_id'=>Auth::schoolId(),'event_type'=>$type,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]); }
}
