<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth};
final class Backup {
    public const TABLES=['roles','academic_years','terms','classes','streams','student_types','students','student_academic_history','users','hostels','rooms','beds','boarding_assignments','fee_types','fee_structures','fee_structure_items','invoices','invoice_items','adjustments','payments','payment_allocations','receipts','audit_logs','school_settings','subscriptions','notification_outbox'];
    public static function create(): string {
        Auth::require('backups.create'); $sid=Auth::schoolId();
        DB::connection()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $data=DB::transaction(function () use($sid) {
            $data=['format'=>'schoolledger-tenant-v1','exported_at'=>date(DATE_ATOM),'school'=>DB::one('SELECT * FROM schools WHERE id=?',[$sid]),'permissions'=>DB::all('SELECT * FROM permissions'),'tables'=>[]];
            foreach(self::TABLES as $table) $data['tables'][$table]=DB::all("SELECT * FROM $table WHERE school_id=?",[$sid]);
            $data['role_permissions']=DB::all('SELECT rp.* FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.school_id=?',[$sid]);
            $data['images']=[]; $names=array_filter([$data['school']['logo_path']]); foreach($data['tables']['students'] as $s) if($s['photo_path']) $names[]=$s['photo_path'];
            foreach(array_unique($names) as $name) { $bytes=ImageStorage::get($sid,$name); if($bytes!==null) $data['images'][$name]=base64_encode($bytes); }
            return $data;
        });
        $name='school-'.$sid.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json.gz';
        if(file_put_contents(ROOT.'/storage/'.$name,gzencode(json_encode($data,JSON_THROW_ON_ERROR),9),LOCK_EX)===false) throw new \RuntimeException('Backup write failed.');
        Auth::audit('backup.created','schools',$sid,$name); return $name;
    }
}
