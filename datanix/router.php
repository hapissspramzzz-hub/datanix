<?php
declare(strict_types=1);
$uri=(string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
$base=(string)(parse_url((string)(getenv('APP_BASE_URL')?:''),PHP_URL_PATH)?:'');
$base=rtrim(str_replace('\\','/',$base),'/');
if($base!==''&&$base!=='/'&&str_starts_with($uri,$base.'/'))$uri=substr($uri,strlen($base));
$uri='/'.ltrim($uri,'/');$private=['/config/','/includes/','/database/','/storage/','/uploads/'];
foreach($private as $prefix){if(str_starts_with($uri,$prefix)){http_response_code(404);exit('Not found');}}
if($uri==='/manus-routes.json'&&is_file(__DIR__.'/manus-routes.json')){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');readfile(__DIR__.'/manus-routes.json');exit;}
if(preg_match('~(?:^|/)\.(?!well-known/)~',$uri)||preg_match('~\.(?:md|sql|jsonl|log)$~i',$uri)){http_response_code(404);exit('Not found');}
if(str_starts_with($uri,'/api/')){$_GET['path']=substr($uri,5);require __DIR__.'/api/index.php';return true;}
if($uri==='/api'||$uri==='/api/'){$uri='/api/index.php';}
if($uri==='/'||$uri==='')$uri='/index.php';
$file=__DIR__.$uri;
if(is_file($file)&&realpath($file)!==false&&str_starts_with((string)realpath($file),__DIR__.DIRECTORY_SEPARATOR))return false;
http_response_code(404);echo 'Halaman tidak ditemukan.';return true;
