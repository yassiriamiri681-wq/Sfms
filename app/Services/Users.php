<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input};
final class Users {
    public static function save(array $data): void {
        Auth::require('users.manage'); DB::transaction(function () use($data) {
            $sid=Auth::schoolId(); $role=Auth::owned('roles',Input::id($data,'role_id')); $email=Input::text($data,'email');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \DomainException('Enter a valid email address.');
            $id=(int)($data['id']??0); if($id) Auth::owned('users',$id);
            if($id===(int)Auth::user()['id'] && (int)$role['id']!==(int)Auth::user()['role_id']) throw new \DomainException('Use another administrator account to change your own role.');
            $password=Input::text($data,'password',200,!$id); if($password!=='' && strlen($password)<12) throw new \DomainException('Use a password of at least 12 characters.');
            $student=(int)($data['student_id'] ?? 0); if($student) Auth::owned('students',$student);
            $selfOnly=DB::one("SELECT p.id FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code='statement.self'",[$role['id']]);
            $readFinance=DB::one("SELECT p.id FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=? AND p.code='finance.read'",[$role['id']]);
            if($selfOnly && !$readFinance && !$student) throw new \DomainException('This role requires a linked student.');
            $values=['role_id'=>$role['id'],'name'=>Input::text($data,'name'),'email'=>strtolower($email),'student_id'=>$student ?: null];
            if($password!=='') { $values['password_hash']=password_hash($password,PASSWORD_DEFAULT); if($id) { $values['session_version']=(int)Auth::owned('users',$id)['session_version']+1; if($id===(int)Auth::user()['id']) $_SESSION['session_version']=$values['session_version']; } }
            if($id) DB::run('UPDATE users SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($values))).' WHERE school_id=? AND id=?',[...array_values($values),$sid,$id]);
            else $id=DB::insert('users',['school_id'=>$sid]+$values);
            Auth::audit('user.saved','users',$id);
        });
    }
    public static function toggle(array $data): void {
        Auth::require('users.manage'); $id=Input::id($data,'id'); $u=Auth::owned('users',$id); if($id==Auth::user()['id']) throw new \DomainException('You cannot deactivate your own account.');
        DB::transaction(function () use($id,$u) { DB::run('UPDATE users SET active=? WHERE school_id=? AND id=?',[$u['active']?0:1,Auth::schoolId(),$id]); Auth::audit('user.status_changed','users',$id); });
    }
    public static function permissions(array $data): void {
        Auth::require('users.manage'); $id=Input::id($data,'role_id'); Auth::owned('roles',$id);
        if($id==(int)Auth::user()['role_id']) throw new \DomainException('Use another administrator role to change your own role permissions.');
        DB::transaction(function () use($data,$id) { DB::run('DELETE FROM role_permissions WHERE role_id=?',[$id]); foreach((array)($data['permissions'] ?? []) as $code) { if(!in_array($code,Schools::PERMISSIONS,true)) throw new \DomainException('Invalid permission.'); DB::run('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?',[$id,$code]); } Auth::audit('role.permissions_changed','roles',$id); });
    }
}
