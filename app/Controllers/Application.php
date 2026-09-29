<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,DB,Input,Navigation};
use App\Services\{Schools,Students,Catalog,Finance,Queries,Users,Uploads,Backup};
final class Application {
    public function run(): void {
        $page=(string)($_GET['page']??'dashboard');
        if($_SERVER['REQUEST_METHOD']==='POST') {
            if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(419); throw new \DomainException('Your form expired. Reload the page and try again.'); }
            if($page==='login') { if(Auth::login(Input::text($_POST,'email'),(string)($_POST['password']??''))) redirect('dashboard'); throw new \DomainException('Sign-in failed. Check your details or wait 15 minutes after repeated attempts.'); }
        }
        if(!Auth::user()) { if($page!=='login') redirect('login'); view('login'); return; }
        if(Auth::user()['school_id'] && !Auth::user()['school_active']) { $this->logout(); }
        if($_SERVER['REQUEST_METHOD']==='POST') { $this->action((string)($_POST['action']??'')); return; }
        if($page==='login') redirect('dashboard');
        if(in_array($page,['platform','global-audit'],true)) {
            if(!Auth::user()['is_super']) { http_response_code(403); throw new \DomainException('Super administrator access required.'); }
            if($page==='platform') $this->render('platform','Platform settings',['settings'=>array_column(DB::all('SELECT * FROM platform_settings'),'setting_value','setting_key')]);
            else $this->render('audit','Platform audit trail',['rows'=>DB::all('SELECT a.*,CONCAT(COALESCE(s.name,\'Platform\'),\' / \',COALESCE(u.name,\'System\')) user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN schools s ON s.id=a.school_id ORDER BY a.id DESC LIMIT 101 OFFSET '.((max(1,(int)($_GET['p']??1))-1)*100))]);
            return;
        }
        if($page==='schools') { if(!Auth::user()['is_super']) throw new \DomainException('Super administrator access required.'); $this->render('schools','Schools',['schools'=>DB::all('SELECT s.*,(SELECT COUNT(*) FROM students st WHERE st.school_id=s.id) student_count FROM schools s ORDER BY s.id DESC')]); return; }
        if(Auth::user()['is_super'] && empty($_SESSION['school_id'])) redirect('schools',['next'=>Navigation::currentDestination($_GET)]);
        $sid=Auth::schoolId();
        switch($page) {
            case 'batch': BatchController::show($this); break;
            case 'dashboard':
                if(!Auth::can('finance.read')) { if(Auth::can('students.read')) redirect('students'); if(Auth::can('statement.self') && Auth::user()['student_id']) redirect('statement',['id'=>Auth::user()['student_id']]); $this->render('empty','Welcome'); return; }
                $stats=DB::one('SELECT COUNT(*) invoices,COALESCE(SUM(total),0) billed,COALESCE(SUM(paid),0) collected,COALESCE(SUM(balance),0) outstanding,COALESCE(SUM(balance>0 AND due_on<CURRENT_DATE),0) overdue_count,COALESCE(SUM(CASE WHEN due_on<CURRENT_DATE THEN balance ELSE 0 END),0) overdue,COALESCE(SUM(balance=0),0) paid_count,COALESCE(SUM(balance>0 AND paid>0),0) partial_count FROM invoice_balances WHERE school_id=? AND cancelled_at IS NULL',[$sid]);
                $types=DB::all('SELECT t.name,t.requires_boarding,COUNT(h.id) n FROM student_types t LEFT JOIN student_academic_history h ON h.school_id=t.school_id AND h.student_type_id=t.id AND h.is_current=1 WHERE t.school_id=? GROUP BY t.id ORDER BY t.id',[$sid]);
                $months=DB::all("SELECT DATE_FORMAT(paid_on,'%Y-%m') month,SUM(amount) amount FROM payments WHERE school_id=? AND reversed_at IS NULL AND paid_on>=DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH) GROUP BY month ORDER BY month",[$sid]);
                $this->render('dashboard','Overview',compact('stats','types','months')+['recent'=>array_slice(Queries::payments(),0,5),'studentCount'=>DB::one('SELECT COUNT(*) n FROM students WHERE school_id=?',[$sid])['n']]); break;
            case 'students': Auth::require('students.read'); $this->render('students','Students',Queries::students($_GET)); break;
            case 'student-form': Auth::require('students.write'); $id=(int)($_GET['id']??0); $this->render('student-form',$id?'Edit student':'Register student',['student'=>$id?Auth::owned('students',$id):[]]); break;
            case 'student': Auth::require('students.read'); $id=Input::id($_GET,'id'); $this->render('student','Student profile',['student'=>Auth::owned('students',$id),'history'=>DB::all('SELECT h.*,y.name year_name,c.name class_name,t.name type_name,st.name stream_name FROM student_academic_history h JOIN academic_years y ON y.school_id=h.school_id AND y.id=h.academic_year_id JOIN classes c ON c.school_id=h.school_id AND c.id=h.class_id JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id LEFT JOIN streams st ON st.school_id=h.school_id AND st.id=h.stream_id WHERE h.school_id=? AND h.student_id=? ORDER BY h.id DESC',[$sid,$id])]); break;
            case 'academics': Auth::require('academics.manage'); $table=(string)($_GET['table']??'academic_years'); if(!isset(Catalog::TYPES[$table]) || $table==='fee_types') throw new \DomainException('Invalid configuration page.'); $this->render('catalog','Academic & boarding setup',['table'=>$table,'editing'=>!empty($_GET['edit'])?Auth::owned($table,Input::id($_GET,'edit')):null,'rows'=>DB::all("SELECT * FROM $table WHERE school_id=? ORDER BY id DESC",[$sid])]); break;
            case 'fee-types': Auth::require('fees.manage'); $this->render('catalog','Fee types',['table'=>'fee_types','editing'=>!empty($_GET['edit'])?Auth::owned('fee_types',Input::id($_GET,'edit')):null,'rows'=>DB::all('SELECT * FROM fee_types WHERE school_id=? ORDER BY name',[$sid])]); break;
            case 'boarding': Auth::require('students.read'); $this->render('boarding','Boarding',['rows'=>DB::all('SELECT a.*,s.first_name,s.last_name,s.admission_number,b.name bed_name,r.name room_name,h.name hostel_name FROM boarding_assignments a JOIN students s ON s.school_id=a.school_id AND s.id=a.student_id JOIN beds b ON b.school_id=a.school_id AND b.id=a.bed_id JOIN rooms r ON r.school_id=b.school_id AND r.id=b.room_id JOIN hostels h ON h.school_id=r.school_id AND h.id=r.hostel_id WHERE a.school_id=? ORDER BY a.is_current DESC,a.id DESC',[$sid])]); break;
            case 'fees': Auth::require('fees.manage'); $id=(int)($_GET['id']??0); $this->render('fees','Fee structures',['structures'=>DB::all('SELECT f.*,y.name year_name,t.name term_name,c.name class_name,s.name type_name,(SELECT SUM(amount) FROM fee_structure_items it WHERE it.school_id=f.school_id AND it.fee_structure_id=f.id) total FROM fee_structures f JOIN academic_years y ON y.school_id=f.school_id AND y.id=f.academic_year_id JOIN terms t ON t.school_id=f.school_id AND t.id=f.term_id JOIN classes c ON c.school_id=f.school_id AND c.id=f.class_id JOIN student_types s ON s.school_id=f.school_id AND s.id=f.student_type_id WHERE f.school_id=? ORDER BY f.id DESC',[$sid]),'selected'=>$id?Auth::owned('fee_structures',$id):null,'items'=>$id?DB::all('SELECT i.*,t.name FROM fee_structure_items i JOIN fee_types t ON t.school_id=i.school_id AND t.id=i.fee_type_id WHERE i.school_id=? AND i.fee_structure_id=?',[$sid,$id]):[]]); break;
            case 'invoices': Auth::require('finance.read'); $this->render('invoices','Invoices',['rows'=>Queries::invoices($_GET)]); break;
            case 'invoice': Auth::require('finance.read'); $id=Input::id($_GET,'id'); $invoice=Finance::balance($id); $this->render('invoice','Invoice '.$invoice['number'],['invoice'=>$invoice,'student'=>Auth::owned('students',(int)$invoice['student_id']),'items'=>DB::all('SELECT * FROM invoice_items WHERE school_id=? AND invoice_id=?',[$sid,$id]),'adjustments'=>DB::all('SELECT * FROM adjustments WHERE school_id=? AND invoice_id=?',[$sid,$id])]); break;
            case 'payments': Auth::require('finance.read'); $this->render('payments','Payments & receipts',['rows'=>Queries::payments($_GET)]); break;
            case 'receipt':
                $id=Input::id($_GET,'id'); $payment=Auth::owned('payments',$id); Auth::studentScope((int)$payment['student_id']);
                $receipt=DB::one('SELECT r.*,p.amount,p.paid_on,p.method,p.reference,p.notes,p.reversed_at,p.reversal_reason,u.name received_name,i.number invoice_number,c.name class_name,y.name year_name,tr.name term_name FROM receipts r JOIN payments p ON p.school_id=r.school_id AND p.id=r.payment_id JOIN users u ON u.id=p.received_by JOIN payment_allocations a ON a.school_id=p.school_id AND a.payment_id=p.id JOIN invoices i ON i.school_id=a.school_id AND i.id=a.invoice_id JOIN student_academic_history h ON h.school_id=i.school_id AND h.id=i.enrollment_id JOIN classes c ON c.school_id=h.school_id AND c.id=h.class_id JOIN academic_years y ON y.school_id=h.school_id AND y.id=h.academic_year_id JOIN terms tr ON tr.school_id=i.school_id AND tr.id=i.term_id WHERE r.school_id=? AND r.payment_id=?',[$sid,$id]);
                $this->render('receipt','Payment receipt',['receipt'=>$receipt,'student'=>Auth::owned('students',(int)$payment['student_id'])]); break;
            case 'statement': $id=Input::id($_GET,'id'); if(!Auth::can('finance.read')) Auth::require('statement.self'); $rows=Queries::statement($id); if(isset($_GET['csv'])) $this->csv('statement',['Date','Description','Debit','Credit','Balance'],array_map(fn($r)=>[$r['date'],$r['description'],$r['amount']>0?$this->decimal($r['amount']):'0.00',$r['amount']<0?$this->decimal(-$r['amount']):'0.00',$this->decimal($r['balance'])],$rows)); $this->render('statement','Student fee statement',['student'=>Auth::owned('students',$id),'rows'=>$rows]); break;
            case 'reports': Auth::require('reports.read'); $this->reports(); break;
            case 'users': Auth::require('users.manage'); $this->render('users','Users & permissions',['editing'=>!empty($_GET['edit'])?Auth::owned('users',Input::id($_GET,'edit')):null,'users'=>DB::all('SELECT u.id,u.name,u.email,u.active,u.student_id,r.name role_name FROM users u JOIN roles r ON r.school_id=u.school_id AND r.id=u.role_id WHERE u.school_id=? ORDER BY u.name',[$sid]),'roles'=>DB::all('SELECT * FROM roles WHERE school_id=?',[$sid])]); break;
            case 'settings': Auth::require('settings.manage'); $this->render('settings','School settings'); break;
            case 'audit': Auth::require('audit.read'); $this->render('audit','Audit trail',['rows'=>DB::all('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.school_id=? ORDER BY a.id DESC LIMIT 101 OFFSET '.((max(1,(int)($_GET['p']??1))-1)*100),[$sid])]); break;
            case 'backups': Auth::require('backups.create'); $this->render('backups','Backups'); break;
            case 'image': $this->image(); break;
            default: http_response_code(404); throw new \DomainException('Page not found.');
        }
    }
    private function action(string $action): never {
        switch($action) {
            case 'batch.preview': BatchController::preview();
            case 'batch.apply': BatchController::apply();
            case 'logout': $this->logout();
            case 'platform.save':
                if(!Auth::user()['is_super']) { http_response_code(403); throw new \DomainException('Super administrator access required.'); }
                DB::transaction(function () { foreach(['instance_name'=>190,'support_email'=>190,'login_notice'=>500] as $key=>$max) { $value=Input::text($_POST,$key,$max,false); if($key==='support_email' && $value && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new \DomainException('Enter a valid support email.'); DB::run('INSERT INTO platform_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?',[$key,$value,$value]); } Auth::audit('platform.settings_changed','platform',0); });
                $this->success('Platform settings saved.','platform');
            case 'school.create': $id=Schools::create($_POST); $_SESSION['school_id']=$id; $this->success('School created. Create its administrator account in Users.','settings');
            case 'school.select': if(!Auth::user()['is_super']) throw new \DomainException('Super administrator access required.'); $id=Input::id($_POST,'school_id'); if(!DB::one('SELECT id FROM schools WHERE id=? AND active=1',[$id])) throw new \DomainException('Select an active school.'); $_SESSION['school_id']=$id; [$destination,$params]=Navigation::target((string)($_POST['next']??'dashboard')); $this->success('School workspace selected.',$destination,$params);
            case 'school.toggle': if(!Auth::user()['is_super']) throw new \DomainException('Super administrator access required.'); $id=Input::id($_POST,'id'); DB::transaction(function () use($id) { DB::run('UPDATE schools SET active=NOT active WHERE id=?',[$id]); Auth::audit('school.status_changed','schools',$id); }); if((int)($_SESSION['school_id']??0)===$id) unset($_SESSION['school_id']); $this->success('School status updated.','schools');
            case 'catalog.save': Catalog::save($_POST); $this->success('Configuration saved.',$_POST['table']==='fee_types'?'fee-types':'academics',['table'=>$_POST['table']]);
            case 'student.save': $id=Students::save($_POST); $this->success('Student saved.','student',['id'=>$id]);
            case 'student.enroll': $id=Input::id($_POST,'student_id'); DB::transaction(fn()=>Students::enroll($id,$_POST)); $this->success('Enrollment saved; previous history retained.','student',['id'=>$id]);
            case 'boarding.save': Students::board($_POST); $this->success('Boarding assignment updated.','boarding');
            case 'fee.create': $id=Catalog::structure($_POST); $this->success('Structure created. Add its fee items.','fees',['id'=>$id]);
            case 'fee.item': Catalog::item($_POST); $this->success('Fee saved. Existing invoices retain their original charges.','fees',['id'=>$_POST['fee_structure_id']]);
            case 'fee.remove': Catalog::removeItem($_POST); $this->success('Fee item removed from future billing.','fees',['id'=>$_POST['fee_structure_id']]);
            case 'invoice.create': $id=Finance::invoice($_POST); $this->success('Invoice generated.','invoice',['id'=>$id]);
            case 'invoice.adjust': Finance::adjust($_POST); $this->success('Adjustment posted.','invoice',['id'=>$_POST['invoice_id']]);
            case 'invoice.cancel': Finance::cancel($_POST); $this->success('Invoice cancelled.','invoice',['id'=>$_POST['invoice_id']]);
            case 'payment.create': $id=Finance::payment($_POST); $this->success('Payment recorded and receipt issued.','receipt',['id'=>$id]);
            case 'payment.reverse': Finance::reverse($_POST); $this->success('Payment reversed. Original receipt remains in the audit history.','payments');
            case 'user.create': Users::save($_POST); $this->success('User saved.','users');
            case 'user.toggle': Users::toggle($_POST); $this->success('User access updated.','users');
            case 'role.permissions': Users::permissions($_POST); $this->success('Role permissions saved.','users');
            case 'role.create': Auth::require('users.manage'); DB::transaction(function () { $id=DB::insert('roles',['school_id'=>Auth::schoolId(),'name'=>Input::text($_POST,'name',100)]); Auth::audit('role.created','roles',$id); }); $this->success('Role created. Assign its permissions below.','users');
            case 'settings.save': $this->settings(); $this->success('School settings saved.','settings');
            case 'image.upload': $kind=Input::choice($_POST,'kind',['student','logo']); $id=$kind==='student'?Input::id($_POST,'id'):Auth::schoolId(); Uploads::save($kind,$id); $this->success('Image uploaded.',$kind==='student'?'student':'settings',['id'=>$id]);
            case 'backup.create': $file=Backup::create(); header('Content-Type: application/gzip'); header('Content-Disposition: attachment; filename="'.$file.'"'); header('Cache-Control: no-store'); readfile(ROOT.'/storage/'.$file); exit;
            case 'password.change': $old=(string)($_POST['current_password']??''); $new=Input::text($_POST,'new_password',200); if(!password_verify($old,Auth::user()['password_hash']) || strlen($new)<12) throw new \DomainException('Check your current password and use at least 12 characters for the new one.'); DB::transaction(function () use($new) { DB::run('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),Auth::user()['id']]); Auth::audit('password.changed','users',(int)Auth::user()['id']); }); $_SESSION['session_version']++; session_regenerate_id(true); $this->success('Password changed.','dashboard');
            default: throw new \DomainException('Unknown action.');
        }
    }
    private function settings(): void {
        Auth::require('settings.manage'); DB::transaction(function () {
            $sid=Auth::schoolId(); $old=DB::one('SELECT * FROM schools WHERE id=? FOR UPDATE',[$sid]); $fields=[];
            foreach(['name'=>190,'address'=>500,'phone'=>60,'email'=>190,'website'=>190,'invoice_prefix'=>12,'receipt_prefix'=>12] as $k=>$max) $fields[$k]=Input::text($_POST,$k,$max,in_array($k,['name','invoice_prefix','receipt_prefix'],true));
            if($fields['email'] && !filter_var($fields['email'],FILTER_VALIDATE_EMAIL)) throw new \DomainException('Enter a valid school email.');
            $currency=strtoupper(Input::text($_POST,'currency',3)); if(!preg_match('/^[A-Z]{3}$/',$currency)) throw new \DomainException('Use a three-letter currency code.');
            if($currency!==$old['currency'] && DB::one('SELECT id FROM invoices WHERE school_id=? LIMIT 1',[$sid])) throw new \DomainException('Currency cannot change after invoices exist.');
            $fields['currency']=$currency;
            DB::run('UPDATE schools SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($fields))).' WHERE id=?',[...array_values($fields),$sid]);
            $year=(int)($_POST['current_year_id']??0); $term=(int)($_POST['current_term_id']??0);
            if($term && (!$year || (int)Auth::owned('terms',$term)['academic_year_id']!==$year)) throw new \DomainException('Current term must belong to the current academic year.');
            foreach(['current_year_id'=>'academic_years','current_term_id'=>'terms'] as $key=>$table) { $value=(int)($_POST[$key]??0); if($value) Auth::owned($table,$value); DB::run('INSERT INTO school_settings (school_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?',[$sid,$key,(string)$value,(string)$value]); }
            Auth::audit('school.settings_changed','schools',$sid);
        });
    }
    private function reports(): void {
        $type=(string)($_GET['type']??'outstanding');
        $types=['outstanding','student-fees','paid','partial','daily','monthly','term','year','method','class','student-type','receipts','discounts','adjustments'];
        if(!in_array($type,$types,true)) throw new \DomainException('Invalid report.');
        $rows=[]; $headers=[];
        if(in_array($type,['outstanding','student-fees','paid','partial','class','student-type'],true)) {
            $filters=$_GET; if($type==='paid') $filters['status']='Paid'; if($type==='partial') $filters['status']='Partially Paid';
            $headers=['Invoice','Admission','Student','Class','Student type','Year','Term','Billed','Paid','Balance','Status'];
            foreach(Queries::invoices($filters,false) as $i) { if($i['cancelled_at'] || ($type==='outstanding' && (int)$i['balance']<=0)) continue; $rows[]=[$i['number'],$i['admission_number'],$i['first_name'].' '.$i['last_name'],$i['class_name'],$i['type_name'],$i['year_name'],$i['term_name'],$this->decimal($i['total']),$this->decimal($i['paid']),$this->decimal($i['balance']),Finance::status($i)]; }
        } elseif(in_array($type,['discounts','adjustments'],true)) {
            $headers=['Date','Invoice','Student','Type','Amount','Reason'];
            $filters=$_GET; unset($filters['from'],$filters['to']);
            foreach(Queries::invoices($filters,false) as $i) {
                $conditions=['school_id=?','invoice_id=?']; $params=[Auth::schoolId(),$i['id']];
                if($type==='discounts') $conditions[]="kind='Discount'";
                foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(!empty($_GET[$key])) { $conditions[]="posted_on $op ?"; $params[]=Input::date($_GET,$key); }
                foreach(DB::all('SELECT * FROM adjustments WHERE '.implode(' AND ',$conditions),$params) as $a) $rows[]=[$a['posted_on'],$i['number'],$i['first_name'].' '.$i['last_name'],$a['kind'],$this->decimal($a['amount']),$a['reason']];
            }
        } else {
            $payments=Queries::payments($_GET,false);
            if($type==='receipts') { $headers=['Receipt','Date','Student','Invoice','Method','Reference','Amount','Status']; foreach($payments as $p) $rows[]=[$p['receipt_number'],$p['paid_on'],$p['first_name'].' '.$p['last_name'],$p['invoice_number'],$p['method'],$p['reference'],$this->decimal($p['amount']),$p['reversed_at']?'Reversed':'Posted']; }
            else { $headers=['Period / group','Payments','Collected']; $groups=[]; foreach($payments as $p) { if($p['reversed_at']) continue; $key=match($type){'daily'=>$p['paid_on'],'monthly'=>substr($p['paid_on'],0,7),'term'=>$p['year_name'].' · '.$p['term_name'],'year'=>$p['year_name'],'method'=>$p['method']}; $groups[$key]??=[0,0]; $groups[$key][0]++; $groups[$key][1]+=(int)$p['amount']; } ksort($groups); foreach($groups as $key=>$value) $rows[]=[$key,$value[0],$this->decimal($value[1])]; }
        }
        if(isset($_GET['csv'])) $this->csv($type,$headers,$rows);
        $this->render('reports','Financial reports',compact('type','types','rows','headers'));
    }
    private function decimal(int|string $v): string { return str_replace(',','',\App\Core\Money::format((int)$v)); }
    private function csv(string $name,array $headers,array $rows): never {
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$name.'-'.date('Ymd').'.csv"'); $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,$headers,',','"','');
        foreach($rows as $row) fputcsv($out,array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$row),',','"',''); fclose($out); exit;
    }
    private function image(): never {
        $kind=(string)($_GET['kind']??'student'); if($kind==='logo') { $row=DB::one('SELECT logo_path path FROM schools WHERE id=?',[Auth::schoolId()]); } else { $id=Input::id($_GET,'id'); if(!Auth::can('students.read')) Auth::studentScope($id); $s=Auth::owned('students',$id); $row=['path'=>$s['photo_path']]; }
        $bytes=\App\Services\ImageStorage::get(Auth::schoolId(),$row['path']??''); if($bytes===null) { http_response_code(404); exit; }
        header('Content-Type: image/png'); header('Cache-Control: private, max-age=300'); echo $bytes; exit;
    }
    public function render(string $template,string $title,array $data=[]): void { $school=isset($_SESSION['user_id']) && (Auth::user()['school_id'] || !empty($_SESSION['school_id']))?DB::one('SELECT * FROM schools WHERE id=?',[Auth::schoolId()]):null; view('layout',compact('template','title','data','school')); }
    private function success(string $message,string $page,array $params=[]): never { $_SESSION['flash']=$message; redirect($page,$params); }
    private function logout(): never { Auth::audit('logout','users',(int)Auth::user()['id']); $_SESSION=[]; session_destroy(); setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']); redirect('login'); }
}
