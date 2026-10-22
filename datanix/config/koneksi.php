<?php
declare(strict_types=1);
date_default_timezone_set(getenv('DATANIX_TIMEZONE') ?: 'Asia/Jakarta');
$host=getenv('DATANIX_DB_HOST')?:'127.0.0.1';
$name=getenv('DATANIX_DB_NAME')?:'datanix';
$user=getenv('DATANIX_DB_USER')?:'root';
$pass=getenv('DATANIX_DB_PASSWORD')?:'';
$charset='utf8mb4';
$dsn="mysql:host={$host};dbname={$name};charset={$charset}";
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"];
try{$pdo=new PDO($dsn,$user,$pass,$options);}catch(PDOException $exception){error_log('Datanix database connection failed: '.$exception->getMessage());http_response_code(503);exit('Database Datanix belum tersedia. Buat database, impor database/schema.sql, lalu periksa pengaturan DATANIX_DB_* di environment.');}
