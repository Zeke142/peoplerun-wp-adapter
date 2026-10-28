<?php
declare(strict_types=1);
// SYNTHETIC TEST ONLY. Never deploy as a real receipt verifier.
function o01_fixture(PDO $db): void {
  $db->exec('CREATE TABLE o01_temp_o01_paid_routes (slug VARCHAR(63) PRIMARY KEY, record_json LONGTEXT NOT NULL) ENGINE=InnoDB');
  $db->exec('CREATE TABLE o01_temp_o01_paid_receipts (request_key VARCHAR(120) PRIMARY KEY, receipt_json LONGTEXT NOT NULL) ENGINE=InnoDB');
  $html='<main>O01 SYNTHETIC WORDPRESS DELIVERY</main>';
  $start=strtotime('2027-01-31T00:00:00Z');$end=strtotime('2027-02-28T00:00:00Z');
  $record=['slug'=>'synthetic-page','owner_id'=>5,'project_id'=>9,'revision_id'=>12,'reservation_id'=>13,'state'=>'published',
    'parent_html_b64'=>base64_encode($html),'html_sha256'=>hash('sha256',$html),'revision_sha256'=>hash('sha256',$html),
    'consent_sha256'=>str_repeat('1',64),'payment_sha256'=>str_repeat('2',64),'publication_sha256'=>str_repeat('3',64),
    'term'=>'month','term_seconds'=>$end-$start,'started_at'=>$start,'expires_at'=>$end];
  $s=$db->prepare('INSERT INTO o01_temp_o01_paid_routes (slug,record_json) VALUES (?,?)');
  $s->execute(['synthetic-page',json_encode($record,JSON_THROW_ON_ERROR)]);
}
