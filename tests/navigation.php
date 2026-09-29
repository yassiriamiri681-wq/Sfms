<?php
declare(strict_types=1);
// Run against the separate test HTTP server; never against a real school.
ob_start();
require __DIR__.'/http.php';
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Navigation;
$startCount=$count;
login('super@example.test');
foreach(Navigation::DESTINATIONS as $destination=>$label) {
    [$page,$params]=Navigation::target($destination);
    [$status,,$headers]=request(url($page,$params));
    check($status===303 && str_contains($headers,'next='.$destination),'Unselected workspace preserves destination '.$destination);
}
foreach(Navigation::DESTINATIONS as $destination=>$label) {
    [$status,$body]=request(url('schools',['next'=>$destination]));
    check($status===200 && str_contains($body,'Select a school to open'),'School selection explains destination '.$destination);
    [$status,,$headers]=request(url('schools'),['action'=>'school.select','csrf'=>token($body),'school_id'=>$fixture['school'],'next'=>$destination]);
    [$page,$params]=Navigation::target($destination);
    $expected=url($page,$params);
    check($status===303 && str_contains($headers,'Location: '.$expected),'School selection links to '.$destination);
    [$status,$body]=request($expected);
    $title=match($destination) {'academics'=>'Academic & boarding setup','reports'=>'Financial reports','class-invoices'=>'Bulk invoice generation','class-promotion'=>'Bulk student promotion',default=>$label};
    check($status===200 && str_contains($body,'<h1>'.htmlspecialchars($title,ENT_QUOTES).'</h1>'),'Destination renders '.$destination);
}
[$status,$body]=request(url('schools'));
[$status,,$headers]=request(url('schools'),['action'=>'school.select','csrf'=>token($body),'school_id'=>$fixture['school'],'next'=>'https://example.invalid']);
check($status===303 && str_contains($headers,'Location: /?page=dashboard'),'Untrusted next cannot redirect externally');
foreach([['student','students'],['student-form','students'],['statement','students'],['fee-types','fees'],['invoice','invoices'],['receipt','payments'],['platform','platform'],['global-audit','global-audit']] as [$page,$parent]) check(Navigation::parent(['page'=>$page])===$parent,'Correct selected menu for '.$page);
check(Navigation::parent(['page'=>'batch','kind'=>'promotion'])==='students','Promotion selects Students');
check(Navigation::parent(['page'=>'batch','kind'=>'billing'])==='invoices','Class billing selects Invoices');
[, $body]=request(url('dashboard'));
foreach(['/?page=batch&amp;kind=promotion','/?page=batch&amp;kind=billing','/?page=fee-types'] as $href) check(str_contains($body,'href="'.$href.'"'),'Sidebar child link '.$href);
request('/',['action'=>'logout','csrf'=>token($body)]);
echo 'PASS: '.($count-$startCount)." navigation checks\n";
ob_end_flush();
