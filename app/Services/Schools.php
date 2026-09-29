<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input};
final class Schools {
    public const PERMISSIONS = ['students.read','students.write','academics.manage','fees.manage','finance.read','invoices.write','payments.write','payments.reverse','reports.read','users.manage','settings.manage','audit.read','backups.create','statement.self'];
    public static function create(array $data): int {
        if (!Auth::user()['is_super']) throw new \DomainException('Super administrator access required.');
        return DB::transaction(function () use ($data) {
            $id=DB::insert('schools',['name'=>Input::text($data,'name'),'currency'=>'TZS']);
            foreach (['Day Scholar'=>0,'Boarding Student'=>1] as $name=>$boarding) DB::insert('student_types',['school_id'=>$id,'name'=>$name,'requires_boarding'=>$boarding]);
            $roles = [
                'School Administrator'=>self::PERMISSIONS,
                'Accountant / Bursar'=>['students.read','finance.read','invoices.write','payments.write','reports.read','statement.self'],
                'Receptionist'=>['students.read','students.write'],
                'Teacher'=>['students.read'], 'Student'=>['statement.self'],
            ];
            foreach ($roles as $name=>$permissions) {
                $rid=DB::insert('roles',['school_id'=>$id,'name'=>$name]);
                foreach ($permissions as $code) DB::run('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?',[$rid,$code]);
            }
            DB::insert('subscriptions',['school_id'=>$id]); Auth::audit('school.created','schools',$id); return $id;
        });
    }
}
