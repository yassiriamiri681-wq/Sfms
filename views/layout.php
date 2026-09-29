<?php use App\Core\{Auth,Navigation}; require_once __DIR__.'/_helpers.php'; $user=Auth::user(); $page=$_GET['page']??'dashboard'; $activeMenu=Navigation::parent($_GET); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> · SchoolLedger</title><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script></head><body>
<aside class="sidebar" id="sidebar"><a class="brand" href="<?= e(url('dashboard')) ?>"><span class="brand-icon">S</span><span>SchoolLedger<small>SCHOOL FINANCE, SIMPLIFIED</small></span></a>
<span class="nav-label">WORKSPACE</span><nav aria-label="Main navigation">
<?php $nav=[['dashboard','Overview','◫','finance.read'],['students','Students','♙','students.read'],['academics','Academic setup','▦','academics.manage'],['boarding','Boarding','⌂','students.read'],['fees','Fee structures','≡','fees.manage'],['invoices','Invoices','▤','finance.read'],['payments','Payments & receipts','↗','finance.read'],['reports','Reports','▥','reports.read']]; foreach($nav as [$route,$label,$icon,$permission]): if(!Auth::can($permission)) continue; ?>
<a class="nav-link <?= $activeMenu===$route?'selected':'' ?>" href="<?= e(url($route)) ?>"><span><?= e($icon) ?></span><?= e($label) ?></a>
<?php
$children = match($route) {
    'students'=>[['class-promotion','Promote a class','students.write']],
    'fees'=>[['fee-types','Fee types','fees.manage']],
    'invoices'=>[['class-invoices','Class invoices','invoices.write']],
    default=>[],
};
foreach($children as [$destination,$childLabel,$childPermission]):
    if(!Auth::can($childPermission)) continue;
    [$childPage,$childParams]=Navigation::target($destination);
?>
<a class="nav-link subnav <?= Navigation::currentDestination($_GET)===$destination?'selected':'' ?>" href="<?= e(url($childPage,$childParams)) ?>"><?= e($childLabel) ?></a>
<?php endforeach; endforeach ?>
<?php if(!Auth::can('finance.read') && Auth::can('statement.self') && $user['student_id']): ?><a class="nav-link selected" href="<?= e(url('statement',['id'=>$user['student_id']])) ?>">My fee statement</a><?php endif ?>
<span class="nav-label">ADMINISTRATION</span>
<?php foreach([['users','Users & permissions','users.manage'],['settings','School settings','settings.manage'],['audit','Audit trail','audit.read'],['backups','Backups','backups.create']] as [$route,$label,$permission]): if(Auth::can($permission)): ?><a class="nav-link <?= $page===$route?'selected':'' ?>" href="<?= e(url($route)) ?>"><span>◇</span><?= e($label) ?></a><?php endif; endforeach ?>
<?php if($user['is_super']): ?><a class="nav-link <?= $page==='schools'?'selected':'' ?>" href="<?= e(url('schools')) ?>"><span>▧</span>Manage schools</a><a class="nav-link <?= $page==='platform'?'selected':'' ?>" href="<?= e(url('platform')) ?>"><span>◇</span>Platform settings</a><a class="nav-link <?= $page==='global-audit'?'selected':'' ?>" href="<?= e(url('global-audit')) ?>"><span>◇</span>Platform audit</a><?php endif ?></nav>
<div class="sidebar-foot"><span class="secure-dot"></span> Private school workspace<small>SchoolLedger · v1.0</small></div>
<div class="workspace workspace-account">
    <div class="workspace-identity"><span class="workspace-icon"><?= $user['is_super']?'A':e(strtoupper(substr($user['name'],0,1))) ?></span><div><strong><?= $user['is_super']?'Administrator':e($user['name']) ?></strong><small><?= e($school['name']??'All schools') ?></small></div></div>
    <form method="post" class="sidebar-logout"><?= csrf() ?><input type="hidden" name="action" value="logout"><button type="submit"><span aria-hidden="true">↪</span> Log out</button></form>
</div></aside>
<div class="app"><header class="topbar"><button class="icon-button mobile-toggle" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">☰</button><div class="breadcrumb">Workspace <span>/</span> <strong><?= e($title) ?></strong></div><div class="topbar-right"><span class="today"><?= e(date('d M Y')) ?></span><span class="avatar"><?= e(strtoupper(substr($user['name'],0,1))) ?></span><details class="account"><summary><?= e($user['name']) ?> <span>⌄</span></summary><div class="account-menu"><p><?= e($user['email']) ?></p><?php startForm('password.change',[],'stack'); field('current_password','Current password','password'); field('new_password','New password · 12+ characters','password'); endForm('Change password'); ?><?php startForm('logout',[],''); endForm('Sign out'); ?></div></details></div></header>
<main><div class="page-heading"><div><p class="eyebrow"><?= e($school['name']??'SCHOOLLEDGER PLATFORM') ?></p><h1><?= e($title) ?></h1></div><div class="heading-meta"><span class="secure-dot"></span> <?= e($school['currency']??'Multi-school') ?> workspace</div></div>
<?php if(isset($_SESSION['flash'])): ?><div class="flash" role="status">✓ <?= e($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif ?>
<?php view($template,$data+['school'=>$school]); ?>
<footer>SchoolLedger <span>Clear records. Confident decisions.</span></footer></main></div></body></html>
