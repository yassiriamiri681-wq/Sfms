<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\Money;
$count=0;
function check(bool $ok,string $message): void { global $count; if(!$ok) throw new RuntimeException($message); $count++; }
foreach(['0'=>0,'0.01'=>1,'400000'=>40000000,'1000000.50'=>100000050,'9999999999.99'=>999999999999] as $input=>$expected) check(Money::parse((string)$input)===$expected,'Exact parse '.$input);
check(Money::parse('-12.34',true)===-1234,'Signed credit');
check(Money::format(100000050)==='1,000,000.50','Formatting');
check(Money::format(-1)==='-0.01','Negative minor unit');
foreach(['1e5','NaN','Infinity','1.001','-2','1,000','','10000000000','0x10','1.2.3'] as $bad) { try { Money::parse($bad); throw new RuntimeException('Accepted invalid input: '.$bad); } catch(DomainException) { $count++; } }
echo "PASS: $count monetary checks\n";
