<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input};
final class Queries {
    public static function options(string $table): array {
        if(!isset(Catalog::TYPES[$table]) && !in_array($table,['roles','students'],true)) throw new \LogicException('Invalid options');
        if($table==='terms') return DB::all("SELECT t.id,CONCAT(y.name,' / ',t.name) name FROM terms t JOIN academic_years y ON y.school_id=t.school_id AND y.id=t.academic_year_id WHERE t.school_id=? ORDER BY y.starts_on DESC,t.starts_on",[Auth::schoolId()]);
        if($table==='streams') return DB::all("SELECT t.id,CONCAT(c.name,' / ',t.name) name FROM streams t JOIN classes c ON c.school_id=t.school_id AND c.id=t.class_id WHERE t.school_id=? ORDER BY c.name,t.name",[Auth::schoolId()]);
        if($table==='rooms') return DB::all("SELECT t.id,CONCAT(h.name,' / ',t.name) name FROM rooms t JOIN hostels h ON h.school_id=t.school_id AND h.id=t.hostel_id WHERE t.school_id=? ORDER BY h.name,t.name",[Auth::schoolId()]);
        $label=$table==='students'?"CONCAT(admission_number,' · ',first_name,' ',last_name)":'name';
        return DB::all("SELECT id,$label name FROM $table WHERE school_id=? ORDER BY name",[Auth::schoolId()]);
    }
    public static function students(array $filters=[]): array {
        $where=['s.school_id=?']; $p=[Auth::schoolId()];
        if(!empty($filters['q'])) { $q='%'.str_replace(['!','%','_'],['!!','!%','!_'],substr((string)$filters['q'],0,190)).'%'; $where[]="(CONCAT_WS(' ',s.first_name,s.middle_name,s.last_name) LIKE ? ESCAPE '!' OR s.admission_number LIKE ? ESCAPE '!' OR s.phone LIKE ? ESCAPE '!' OR s.guardian_phone LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!' OR st.name LIKE ? ESCAPE '!')"; array_push($p,$q,$q,$q,$q,$q,$q); }
        foreach(['class_id','stream_id','student_type_id','academic_year_id'] as $key) if(!empty($filters[$key])) { $where[]="h.$key=?"; $p[]=Input::id($filters,$key); }
        $page=max(1,(int)($filters['p']??1)); $offset=($page-1)*25;
        $from=' FROM students s LEFT JOIN student_academic_history h ON h.school_id=s.school_id AND h.student_id=s.id AND h.is_current=1 LEFT JOIN classes c ON c.school_id=h.school_id AND c.id=h.class_id LEFT JOIN streams st ON st.school_id=h.school_id AND st.id=h.stream_id LEFT JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id LEFT JOIN academic_years y ON y.school_id=h.school_id AND y.id=h.academic_year_id WHERE '.implode(' AND ',$where);
        return ['rows'=>DB::all('SELECT s.*,c.name class_name,st.name stream_name,t.name type_name,t.requires_boarding,y.name year_name'.$from.' ORDER BY s.id DESC LIMIT 25 OFFSET '.$offset,$p),'total'=>(int)DB::one('SELECT COUNT(*) n'.$from,$p)['n'],'page'=>$page];
    }
    public static function invoices(array $filters=[],bool $limit=true): array {
        $where=['i.school_id=?']; $p=[Auth::schoolId()];
        foreach(['student_id'=>'i','term_id'=>'i','class_id'=>'h','stream_id'=>'h','student_type_id'=>'h','academic_year_id'=>'h'] as $key=>$alias) if(!empty($filters[$key])) { $where[]="$alias.$key=?"; $p[]=Input::id($filters,$key); }
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(!empty($filters[$key])) { $where[]="i.issued_on $op ?"; $p[]=Input::date($filters,$key); }
        if(!empty($filters['status'])) { $status=Input::choice($filters,'status',['Unpaid','Partially Paid','Paid','Overdue','Cancelled']); $where[]=match($status){'Cancelled'=>'i.cancelled_at IS NOT NULL','Paid'=>'i.cancelled_at IS NULL AND i.balance=0','Overdue'=>'i.cancelled_at IS NULL AND i.balance>0 AND i.due_on<CURRENT_DATE','Partially Paid'=>'i.cancelled_at IS NULL AND i.balance>0 AND i.paid>0',default=>'i.cancelled_at IS NULL AND i.paid=0 AND i.balance>0'}; }
        $sql='SELECT i.*,s.first_name,s.last_name,s.admission_number,c.name class_name,t.name type_name,y.name year_name,tr.name term_name FROM invoice_balances i JOIN students s ON s.school_id=i.school_id AND s.id=i.student_id JOIN student_academic_history h ON h.school_id=i.school_id AND h.id=i.enrollment_id JOIN classes c ON c.school_id=h.school_id AND c.id=h.class_id JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id JOIN academic_years y ON y.school_id=h.school_id AND y.id=h.academic_year_id JOIN terms tr ON tr.school_id=i.school_id AND tr.id=i.term_id WHERE '.implode(' AND ',$where).' ORDER BY i.id DESC';
        return DB::all($sql.($limit?' LIMIT 51 OFFSET '.((max(1,(int)($filters['p']??1))-1)*50):''),$p);
    }
    public static function payments(array $filters=[],bool $limit=true): array {
        $where=['p.school_id=?']; $args=[Auth::schoolId()];
        foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(!empty($filters[$key])) { $where[]="p.paid_on $op ?"; $args[]=Input::date($filters,$key); }
        if(!empty($filters['method'])) { $where[]='p.method=?'; $args[]=Input::choice($filters,'method',['Cash','Bank','Mobile Money','Cheque','Other']); }
        foreach(['academic_year_id'=>'h','term_id'=>'i','class_id'=>'h','stream_id'=>'h','student_type_id'=>'h'] as $key=>$alias) if(!empty($filters[$key])) { $where[]="$alias.$key=?"; $args[]=Input::id($filters,$key); }
        return DB::all('SELECT p.*,r.number receipt_number,r.balance_after,i.number invoice_number,i.id invoice_id,s.first_name,s.last_name,s.admission_number,u.name received_name,y.name year_name,tr.name term_name,c.name class_name,t.name type_name FROM payments p JOIN receipts r ON r.school_id=p.school_id AND r.payment_id=p.id JOIN payment_allocations a ON a.school_id=p.school_id AND a.payment_id=p.id JOIN invoices i ON i.school_id=a.school_id AND i.id=a.invoice_id JOIN student_academic_history h ON h.school_id=i.school_id AND h.id=i.enrollment_id JOIN academic_years y ON y.school_id=h.school_id AND y.id=h.academic_year_id JOIN terms tr ON tr.school_id=i.school_id AND tr.id=i.term_id JOIN classes c ON c.school_id=h.school_id AND c.id=h.class_id JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id JOIN students s ON s.school_id=p.school_id AND s.id=p.student_id JOIN users u ON u.id=p.received_by WHERE '.implode(' AND ',$where).' ORDER BY p.paid_on DESC,p.id DESC'.($limit?' LIMIT 51 OFFSET '.((max(1,(int)($filters['p']??1))-1)*50):''),$args);
    }
    public static function statement(int $id): array {
        Auth::studentScope($id); $sid=Auth::schoolId(); $rows=[];
        foreach(DB::all('SELECT * FROM invoice_balances WHERE school_id=? AND student_id=?',[$sid,$id]) as $i) {
            $original=DB::one('SELECT SUM(amount) amount FROM invoice_items WHERE school_id=? AND invoice_id=?',[$sid,$i['id']]);
            $rows[]=['date'=>$i['issued_on'],'sort'=>$i['created_at'].'-0-'.$i['id'],'description'=>'Invoice '.$i['number'],'amount'=>(int)$original['amount']];
            foreach(DB::all('SELECT * FROM adjustments WHERE school_id=? AND invoice_id=?',[$sid,$i['id']]) as $a) $rows[]=['date'=>$a['posted_on'],'sort'=>$a['created_at'].'-1-'.$a['id'],'description'=>$a['kind'].' · '.$i['number'].' · '.$a['reason'],'amount'=>(int)$a['amount']];
            if($i['cancelled_at']) $rows[]=['date'=>substr($i['cancelled_at'],0,10),'sort'=>$i['cancelled_at'].'-9-'.$i['id'],'description'=>'Cancelled '.$i['number'].' · '.$i['cancellation_reason'],'amount'=>-(int)$i['total']];
        }
        foreach(DB::all('SELECT p.*,r.number FROM payments p JOIN receipts r ON r.school_id=p.school_id AND r.payment_id=p.id WHERE p.school_id=? AND p.student_id=?',[$sid,$id]) as $p) {
            $rows[]=['date'=>$p['paid_on'],'sort'=>$p['created_at'].'-2-'.$p['id'],'description'=>'Payment '.$p['number'].' · '.$p['method'],'amount'=>-(int)$p['amount']];
            if($p['reversed_at']) $rows[]=['date'=>substr($p['reversed_at'],0,10),'sort'=>$p['reversed_at'].'-3-'.$p['id'],'description'=>'Reversal '.$p['number'].' · '.$p['reversal_reason'],'amount'=>(int)$p['amount']];
        }
        usort($rows,fn($a,$b)=>[$a['date'],$a['sort']]<=>[$b['date'],$b['sort']]); $balance=0;
        foreach($rows as &$r) { $balance+=$r['amount']; $r['balance']=$balance; } unset($r); return $rows;
    }
}
