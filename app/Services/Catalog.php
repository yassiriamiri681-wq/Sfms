<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB,Auth,Input,Money};
final class Catalog {
    public const TYPES = [
        'academic_years'=>['Academic years','academics.manage',['name'=>'text','starts_on'=>'date','ends_on'=>'date']],
        'terms'=>['Terms','academics.manage',['academic_year_id'=>'academic_years','name'=>'text','starts_on'=>'date','ends_on'=>'date']],
        'classes'=>['Classes','academics.manage',['name'=>'text']],
        'streams'=>['Streams','academics.manage',['class_id'=>'classes','name'=>'text']],
        'student_types'=>['Student types','academics.manage',['name'=>'text','requires_boarding'=>'boolean']],
        'hostels'=>['Hostels','academics.manage',['name'=>'text']],
        'rooms'=>['Rooms','academics.manage',['hostel_id'=>'hostels','name'=>'text']],
        'beds'=>['Beds','academics.manage',['room_id'=>'rooms','name'=>'text']],
        'fee_types'=>['Fee types','fees.manage',['name'=>'text']],
    ];
    public static function save(array $data): void {
        $table=Input::choice($data,'table',array_keys(self::TYPES)); [, $permission,$fields]=self::TYPES[$table]; Auth::require($permission);
        DB::transaction(function () use($data,$table,$fields) {
            $values=['school_id'=>Auth::schoolId()];
            foreach($fields as $key=>$type) {
                $values[$key]=match($type) {'text'=>Input::text($data,$key,100),'date'=>Input::date($data,$key),'boolean'=>empty($data[$key])?0:1,default=>Input::id($data,$key)};
                if(isset(self::TYPES[$type])) Auth::owned($type,(int)$values[$key]);
            }
            if(isset($values['ends_on']) && $values['ends_on']<$values['starts_on']) throw new \DomainException('End date must follow the start date.');
            if($table==='terms') { $year=Auth::owned('academic_years',(int)$values['academic_year_id']); if($values['starts_on']<$year['starts_on'] || $values['ends_on']>$year['ends_on']) throw new \DomainException('Term dates must fall within the academic year.'); }
            $id=(int)($data['id']??0);
            if($id) {
                $old=Auth::owned($table,$id);
                // Relationship/date/category changes could reinterpret historical invoices.
                foreach($fields as $key=>$type) if($key!=='name' && (string)$old[$key] !== (string)$values[$key]) throw new \DomainException('Only names can be edited after creation. Create a new configuration for different dates, relationships or boarding rules.');
                DB::run("UPDATE $table SET name=? WHERE school_id=? AND id=?",[$values['name'],Auth::schoolId(),$id]);
            } else $id=DB::insert($table,$values);
            Auth::audit('configuration.saved',$table,$id);
        });
    }
    public static function structure(array $data): int {
        Auth::require('fees.manage'); return DB::transaction(function () use($data) {
            $values=['school_id'=>Auth::schoolId()];
            foreach(['academic_year_id'=>'academic_years','term_id'=>'terms','class_id'=>'classes','student_type_id'=>'student_types'] as $key=>$table) { $values[$key]=Input::id($data,$key); Auth::owned($table,$values[$key]); }
            $term=Auth::owned('terms',$values['term_id']); if((int)$term['academic_year_id']!==$values['academic_year_id']) throw new \DomainException('Term must belong to the selected academic year.');
            $id=DB::insert('fee_structures',$values); Auth::audit('fee_structure.created','fee_structures',$id); return $id;
        });
    }
    public static function item(array $data): void {
        Auth::require('fees.manage'); DB::transaction(function () use($data) {
            $sid=Auth::schoolId(); $id=Input::id($data,'fee_structure_id'); Auth::owned('fee_structures',$id);
            DB::one('SELECT id FROM fee_structures WHERE school_id=? AND id=? FOR UPDATE',[$sid,$id]);
            $type=Input::id($data,'fee_type_id'); Auth::owned('fee_types',$type); $amount=Money::parse(Input::text($data,'amount'));
            if($amount<=0) throw new \DomainException('Fee must be positive.');
            DB::upsert('fee_structure_items',['school_id'=>$sid,'fee_structure_id'=>$id,'fee_type_id'=>$type,'amount'=>$amount],['school_id','fee_structure_id','fee_type_id'],'amount'); Auth::audit('fee_structure.changed','fee_structures',$id);
        });
    }
    public static function removeItem(array $data): void { Auth::require('fees.manage'); DB::transaction(function () use($data) { $id=Input::id($data,'fee_structure_id'); Auth::owned('fee_structures',$id); DB::one('SELECT id FROM fee_structures WHERE school_id=? AND id=? FOR UPDATE',[Auth::schoolId(),$id]); DB::run('DELETE FROM fee_structure_items WHERE school_id=? AND fee_structure_id=? AND fee_type_id=?',[Auth::schoolId(),$id,Input::id($data,'fee_type_id')]); Auth::audit('fee_structure.changed','fee_structures',$id); }); }
}
