<?php
use App\Services\Queries;
function field(string $name,string $label,string $type='text',mixed $value='',bool $required=true): void { echo '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'"'.($required?' required':'').($type==='number'?' step="0.01"':'').' maxlength="'.($type==='password'?'200':'500').'"></label>'; }
function selectField(string $name,string $label,string|array $options,mixed $value='',bool $required=true): void {
    if($required && $value==='' && in_array($name,['academic_year_id','term_id'],true)) {
        $key=$name==='academic_year_id'?'current_year_id':'current_term_id';
        $default=App\Core\DB::one('SELECT setting_value FROM school_settings WHERE school_id=? AND setting_key=?',[App\Core\Auth::schoolId(),$key]);
        $value=$default['setting_value']??'';
    }
    if(is_string($options)) $options=Queries::options($options);
    echo '<label>'.e($label).'<select name="'.e($name).'"'.($required?' required':'').'><option value="">'.($required?'Select…':'All / none').'</option>';
    foreach($options as $o) { if(is_string($o)) $o=['id'=>$o,'name'=>$o]; echo '<option value="'.e($o['id']).'"'.((string)$o['id']===(string)$value?' selected':'').'>'.e($o['name']).'</option>'; }
    echo '</select></label>';
}
function startForm(string $action,array $hidden=[],string $class='form-grid'): void { echo '<form method="post" class="'.e($class).'">'.csrf().'<input type="hidden" name="action" value="'.e($action).'">'; foreach($hidden as $k=>$v) echo '<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; }
function endForm(string $label='Save'): void { echo '<div class="form-actions"><button class="button primary" type="submit">'.e($label).'</button></div></form>'; }
function enrollmentFields(array $values=[]): void { foreach(['academic_year_id'=>['Academic year','academic_years'],'class_id'=>['Class','classes'],'stream_id'=>['Stream','streams'],'student_type_id'=>['Student type','student_types']] as $key=>[$label,$table]) selectField($key,$label,$table,$values[$key]??'',$key!=='stream_id'); field('starts_on','Enrollment starts','date',$values['starts_on']??date('Y-m-d')); }
function emptyRow(int $cols,string $text='No records yet. Your records will appear here once added.'): void { echo '<tr><td colspan="'.$cols.'" class="empty">'.e($text).'</td></tr>'; }
function badge(string $status): void { $tone=match($status){'Paid','Active','Posted'=>'good','Overdue','Reversed','Cancelled','Suspended'=>'bad',default=>'neutral'}; echo '<span class="badge '.$tone.'">'.e($status).'</span>'; }
function pagination(int $page,bool $more): void { echo '<div class="pagination"><span>Page '.e($page).'</span><div>'; if($page>1) echo '<a class="button" href="'.e('/?'.http_build_query(array_merge($_GET,['p'=>$page-1]))).'">Previous</a> '; if($more) echo '<a class="button" href="'.e('/?'.http_build_query(array_merge($_GET,['p'=>$page+1]))).'">Next</a>'; echo '</div></div>'; }
function reportFilters(bool $method=false): void {
    field('from','From date','date',$_GET['from']??'',false); field('to','To date','date',$_GET['to']??'',false);
    foreach(['academic_year_id'=>['Academic year','academic_years'],'term_id'=>['Term','terms'],'class_id'=>['Class','classes'],'stream_id'=>['Stream','streams'],'student_type_id'=>['Student type','student_types']] as $key=>[$label,$table]) selectField($key,$label,$table,$_GET[$key]??'',false);
    if($method) selectField('method','Payment method',['Cash','Bank','Mobile Money','Cheque','Other'],$_GET['method']??'',false);
}
