<?php
declare(strict_types=1);
require __DIR__.'/WPMySQLPaidRouteStore.php';
function ensure(bool $condition, string $message):void {if (!$condition) throw new RuntimeException($message); echo "PASS $message\n";}
$db = new PDO((string)getenv('O01_DSN'), (string)getenv('O01_DB_USER'), (string)getenv('O01_DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TABLE o01_temp_o01_paid_routes (slug VARCHAR(63) PRIMARY KEY,record_json LONGTEXT NOT NULL) ENGINE=InnoDB');
$db->exec('CREATE TABLE o01_temp_o01_paid_receipts (request_key VARCHAR(120) PRIMARY KEY,receipt_json LONGTEXT NOT NULL) ENGINE=InnoDB');
$store=new O01WPMySQLPaidRouteStore($db,'o01_temp_');
ensure($store->begin('demo-test','request-0000000000001'), 'real advisory lock and begin');
ensure($store->put('demo-test',['state'=>'published','html_sha256'=>str_repeat('a',64)]), 'real record write');
ensure($store->receipt('request-0000000000001',['status'=>'committed']), 'real receipt write');
ensure($store->commit(), 'real InnoDB commit');
$read=new O01WPMySQLPaidRouteStore($db,'o01_temp_');
ensure($read->begin('demo-test','request-0000000000002'), 'new real transaction');
ensure(($read->findSlug('demo-test')['state']??null)==='published','persisted route visible');
ensure(($read->findRequest('request-0000000000001')['status']??null)==='committed','persisted receipt visible');
$read->rollback();
ensure($read->begin('rollback-test','request-0000000000003'), 'rollback transaction begins');
ensure($read->put('rollback-test',['state'=>'published']), 'uncommitted row inserted');
$read->rollback();
ensure($read->begin('rollback-test','request-0000000000004'), 'rollback confirmation begins');
ensure($read->findSlug('rollback-test')===null,'rollback removed uncommitted write');
$read->rollback();
echo "REAL_INNODB_ACCEPTANCE_COMPLETED\n";
