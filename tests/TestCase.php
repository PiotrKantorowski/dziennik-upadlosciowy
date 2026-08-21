<?php
require_once dirname(__DIR__).'/app/Bootstrap.php';
\Duir\Bootstrap::init(dirname(__DIR__));
function assert_true($cond, string $msg='assert failed'): void { if(!$cond) throw new RuntimeException($msg); }
function assert_eq($a,$b,string $msg='assert eq failed'): void { if($a!=$b) throw new RuntimeException($msg.' got='.var_export($a,true).' expected='.var_export($b,true)); }
