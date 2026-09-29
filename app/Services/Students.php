<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input};
final class Students {
    public static function save(array $data): int {
        Auth::require('students.write');
        return DB::transaction(function () use($data) {
            $sid=Auth::schoolId(); $id=(int)($data['id'] ?? 0);
            if($id) Auth::owned('students',$id);
            $fields=[];
            foreach(['admission_number','first_name','last_name'] as $key) $fields[$key]=Input::text($data,$key,$key==='admission_number'?80:100);
            foreach(['middle_name'=>100,'phone'=>60,'email'=>190,'guardian_name'=>190,'guardian_phone'=>60,'address'=>500] as $key=>$max) $fields[$key]=Input::text($data,$key,$max,false);
            if($fields['email'] && !filter_var($fields['email'],FILTER_VALIDATE_EMAIL)) throw new \DomainException('Enter a valid email.');
            $fields['gender']=Input::choice($data,'gender',['Female','Male','Other']);
            $fields['date_of_birth']=Input::date($data,'date_of_birth'); $fields['admission_date']=Input::date($data,'admission_date');
            if($fields['date_of_birth']>date('Y-m-d') || $fields['date_of_birth']>$fields['admission_date']) throw new \DomainException('Check the birth and admission dates.');
            $fields['status']=Input::choice($data,'status',['Active','Graduated','Transferred','Withdrawn','Suspended']);
            if($id) { DB::run('UPDATE students SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($fields))).' WHERE school_id=? AND id=?',[...array_values($fields),$sid,$id]); }
            else { $id=DB::insert('students',['school_id'=>$sid]+$fields); self::enroll($id,$data); }
            Auth::audit(isset($data['id'])?'student.updated':'student.created','students',$id); return $id;
        });
    }
    public static function enroll(int $id,array $data): void {
        Auth::require('students.write'); $sid=Auth::schoolId(); Auth::owned('students',$id);
        DB::one('SELECT id FROM students WHERE school_id=? AND id=? FOR UPDATE',[$sid,$id]);
        $year=Auth::owned('academic_years',Input::id($data,'academic_year_id'));
        $class=Auth::owned('classes',Input::id($data,'class_id')); $type=Auth::owned('student_types',Input::id($data,'student_type_id'));
        $stream=(int)($data['stream_id'] ?? 0);
        if($stream && (int)Auth::owned('streams',$stream)['class_id']!==(int)$class['id']) throw new \DomainException('Stream must belong to the selected class.');
        $date=Input::date($data,'starts_on'); if($date<$year['starts_on'] || $date>$year['ends_on']) throw new \DomainException('Enrollment date must fall in the academic year.');
        $current=DB::one('SELECT * FROM student_academic_history WHERE school_id=? AND student_id=? AND is_current=1',[$sid,$id]);
        if($current && $date<$current['starts_on']) throw new \DomainException('New enrollment cannot precede current enrollment.');
        if($current && (int)$current['academic_year_id']===(int)$year['id'] && (int)$current['class_id']===(int)$class['id'] && (int)$current['stream_id']===$stream && (int)$current['student_type_id']===(int)$type['id']) throw new \DomainException('This is already the current enrollment.');
        DB::run('UPDATE student_academic_history SET is_current=NULL WHERE school_id=? AND student_id=? AND is_current=1',[$sid,$id]);
        DB::insert('student_academic_history',['school_id'=>$sid,'student_id'=>$id,'academic_year_id'=>$year['id'],'class_id'=>$class['id'],'stream_id'=>$stream ?: null,'student_type_id'=>$type['id'],'starts_on'=>$date]);
        if(!$type['requires_boarding']) DB::run('UPDATE boarding_assignments SET is_current=NULL,ends_on=? WHERE school_id=? AND student_id=? AND is_current=1',[$date,$sid,$id]);
        Auth::audit('student.enrolled','students',$id);
    }
    public static function board(array $data): void {
        Auth::require('students.write'); DB::transaction(function () use($data) {
            $sid=Auth::schoolId(); $student=Input::id($data,'student_id'); Auth::owned('students',$student);
            DB::one('SELECT id FROM students WHERE school_id=? AND id=? FOR UPDATE',[$sid,$student]);
            $h=DB::one('SELECT t.requires_boarding FROM student_academic_history h JOIN student_types t ON t.school_id=h.school_id AND t.id=h.student_type_id WHERE h.school_id=? AND h.student_id=? AND h.is_current=1',[$sid,$student]);
            if(!$h || !$h['requires_boarding']) throw new \DomainException('Only boarding student types can be assigned a bed.');
            $date=Input::date($data,'starts_on');
            DB::run('UPDATE boarding_assignments SET is_current=NULL,ends_on=? WHERE school_id=? AND student_id=? AND is_current=1',[$date,$sid,$student]);
            if(empty($data['release'])) { $bed=Input::id($data,'bed_id'); Auth::owned('beds',$bed); DB::insert('boarding_assignments',['school_id'=>$sid,'student_id'=>$student,'bed_id'=>$bed,'meals'=>Input::text($data,'meals',190,false),'starts_on'=>$date]); }
            Auth::audit('boarding.updated','students',$student);
        });
    }
}
