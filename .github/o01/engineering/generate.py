#!/usr/bin/env python3
"""Generate PRIVATE mechanics derivative; never edits the original DN29 files."""
import argparse, hashlib, json, pathlib, zipfile, io, difflib
ARCHIVE='b266241398c3f22bbc58a5b8d28250fcf6d8894257cf697a50c0eecf0cca5871'
PINS={'src/o01-dn29-scoped-publisher.php':'878fbdb3a270295a5c91303ee964362343a1347c611c83417b71b79ec8a26165','src/o01-receipt-admission-dn25.php':'518713101c1ba430056d79b75e0f9e71a98ebb2051003c27ed2a8d108acd3be8'}
def sha(b): return hashlib.sha256(b).hexdigest()
def once(s,a,b):
    if s.count(a)!=1: raise ValueError('nonunique transformation anchor')
    return s.replace(a,b,1)
def segment(s,start,end):
    if s.count(start)!=1 or s.count(end)!=1: raise ValueError('nonunique extraction anchor')
    return s[s.index(start):s.index(end)]
def generate(raw):
    if sha(raw)!=ARCHIVE: raise ValueError('DN29 archive pin mismatch')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        if len(z.namelist())!=len(set(z.namelist())): raise ValueError('duplicate ZIP paths')
        src={p:z.read(p) for p in PINS}
    for p,b in src.items():
        if sha(b)!=PINS[p]: raise ValueError('runtime source pin mismatch')
    pub=src['src/o01-dn29-scoped-publisher.php'].decode()
    atomic=src['src/o01-receipt-admission-dn25.php'].decode()
    pub=segment(pub,'    $r=o01_dn22_release_table();$l=o01_dn22_ledger_table();\n    if(!o01_dn22_safe_table','function o01_dn22_delivery')
    pub=once(pub,'$success=o01_dn25_admit_atomic($wpdb,$a,$p,$c,$handoff,$now,','$success=o01_eng_atomic($wpdb,$ids,')
    pub=once(pub,"'state'=>'transaction_committed_reported'","'state'=>'engineering_transaction_committed_reported'")
    atom=atomic[atomic.index('function o01_dn25_admit_atomic('):]
    atom=once(atom,'function o01_dn25_admit_atomic($db,array $approval,array $payment,array $candidate,array $handoff,int $now,callable $publish): bool {\n    $ids=o01_dn25_identifiers($approval,$payment,$candidate,$handoff,$now);\n    if ($ids===null) return false;','function o01_eng_atomic($db,array $ids,callable $publish): bool {\n    o01_eng_ids($ids);')
    pre='''<?php
declare(strict_types=1);
// PRIVATE ENGINEERING DERIVATIVE. Not an admitted publisher or payment verifier.
if (PHP_SAPI !== 'cli' || getenv('O01_ENGINEERING_EXECUTE') !== '1') throw new RuntimeException('engineering_cli_only');
function o01_eng_ids(array $ids): void {
    foreach (['customer_id','approval_issuer','consent_ref','payment_provider','event_id','payment_id'] as $k)
        if (!isset($ids[$k]) || !is_string($ids[$k]) || !preg_match('/^eng_[A-Za-z0-9_.:-]{1,150}$/D',$ids[$k])) throw new RuntimeException('engineering_identifier_required');
    if ($ids['payment_provider']!=='eng_no_payment' || !preg_match('/^[a-f0-9]{64}$/D',$ids['manifest_sha256']??'') || !is_int($ids['timestamp']??null)) throw new RuntimeException('engineering_scope_required');
}
function o01_eng_publish(array $c,array $ids): array {
    o01_eng_ids($ids);
    global $wpdb;
'''
    module=pre+pub+'\n'+atom
    # Mechanical inverse checks establish exact retained bodies, not runtime correctness.
    restored=pub.replace('$success=o01_eng_atomic($wpdb,$ids,','$success=o01_dn25_admit_atomic($wpdb,$a,$p,$c,$handoff,$now,',1).replace("'state'=>'engineering_transaction_committed_reported'","'state'=>'transaction_committed_reported'",1)
    assert restored==segment(src['src/o01-dn29-scoped-publisher.php'].decode(),'    $r=o01_dn22_release_table();$l=o01_dn22_ledger_table();\n    if(!o01_dn22_safe_table','function o01_dn22_delivery')
    oldhead='function o01_dn25_admit_atomic($db,array $approval,array $payment,array $candidate,array $handoff,int $now,callable $publish): bool {\n    $ids=o01_dn25_identifiers($approval,$payment,$candidate,$handoff,$now);\n    if ($ids===null) return false;'
    inv=atom.replace('function o01_eng_atomic($db,array $ids,callable $publish): bool {\n    o01_eng_ids($ids);',oldhead,1)
    assert inv==atomic[atomic.index('function o01_dn25_admit_atomic('):]
    return module.encode(),{'schema':'peoplerun.engineering.allowed-diff.v1','original_archive_sha256':ARCHIVE,'source_sha256':PINS,'generated_sha256':sha(module.encode()),'inverse_checks':{'publisher_body':True,'atomic_body':True},'allowed_changes':['post-trust CLI entry with synthetic identifiers','atomic entry identifiers replaced; transaction body retained','renamed engineering result state'],'classification':'SOURCE_EQUIVALENCE_ONLY_RUNTIME_UNEXECUTED'}
def main():
    ap=argparse.ArgumentParser();ap.add_argument('archive');ap.add_argument('output');a=ap.parse_args()
    module,review=generate(pathlib.Path(a.archive).read_bytes());d=pathlib.Path(a.output);d.mkdir(mode=0o700,parents=True,exist_ok=True)
    (d/'mechanics.php').write_bytes(module);(d/'mechanics.php').chmod(0o600);(d/'allowed-diff.json').write_text(json.dumps(review,indent=2)+'\n');print(json.dumps(review))
if __name__=='__main__': main()
