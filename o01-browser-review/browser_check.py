"""Recovered DN57 secure-context Chromium review; no hash mocks or publishing."""
import functools
import hashlib
import http.server
import json
import os
from pathlib import Path
import tempfile
import threading
import zipfile

ARCHIVE_SHA = '6792194c78dc4c43cf1627289e692923b92143757c568d751e9e9c49ddff62d0'

def main():
    from playwright.sync_api import sync_playwright, expect
    archive = Path(__file__).with_name('O01_DN57_End_to_End_Local_Review.zip')
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == ARCHIVE_SHA
    output = Path(os.environ.get('O01_TEST_OUTPUT', 'o01-browser-results')).resolve()
    output.mkdir(parents=True, exist_ok=True)
    results = {'status': 'FAIL', 'scope': 'SYNTHETIC_LOCAL_BROWSER_ONLY',
               'source_archive_sha256': ARCHIVE_SHA, 'commit': os.environ.get('GITHUB_SHA'),
               'run_id': os.environ.get('GITHUB_RUN_ID'), 'checks': [],
               'customer_approval': None, 'publication_authorized': False}
    def passed(name):
        results['checks'].append(name)
        print('PASS ' + name, flush=True)
    try:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            with zipfile.ZipFile(archive) as z:
                for info in z.infolist():
                    target = (root / info.filename).resolve()
                    assert target.is_relative_to(root), 'unsafe archive path'
                assert z.testzip() is None
                z.extractall(root)
            receipt = json.loads((root/'review-receipt.json').read_text())
            intake = json.loads((root/'synthetic_intake.json').read_text())
            canon = lambda obj: json.dumps(obj, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()
            html = (root/'final-review.html').read_bytes()
            assert hashlib.sha256(html).hexdigest() == receipt['render_sha256']
            assert hashlib.sha256(canon(intake)).hexdigest() == receipt['source_intake_sha256']
            assert hashlib.sha256(canon({k:v for k,v in receipt.items() if k!='receipt_sha256'})).hexdigest() == receipt['receipt_sha256']
            passed('recovered archive and linked intake HTML receipt integrity')
            handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=tmp)
            server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            origin = 'http://127.0.0.1:' + str(server.server_port)
            try:
                with sync_playwright() as pw:
                    browser = pw.chromium.launch(headless=True)
                    results['browser_version'] = browser.version
                    page = browser.new_page(viewport={'width':390,'height':844})
                    errors = []
                    page.on('pageerror', lambda e: errors.append(str(e)))
                    page.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') else route.abort())
                    page.goto(origin+'/Genuine_Intake_on_iPhone.html', wait_until='load')
                    assert page.evaluate('window.isSecureContext && typeof crypto.subtle.digest === "function"')
                    # A known answer computed by the real browser, never an injected digest implementation.
                    actual = page.evaluate('''async () => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode('abc')))).map(x=>x.toString(16).padStart(2,'0')).join('')''')
                    assert actual == 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
                    passed('real browser WebCrypto SHA-256 known-answer in loopback secure context')
                    def load_good():
                        page.locator('#intakeFile').set_input_files(root/'synthetic_intake.json')
                        expect(page.locator('#renderStatus')).to_contain_text('INTAKE LOADED')
                        page.locator('#renderFile').set_input_files(root/'final-review.html')
                        page.locator('#receiptFile').set_input_files(root/'review-receipt.json')
                        page.locator('#verifyRender').click()
                        expect(page.locator('#renderStatus')).to_contain_text('RENDER HASH MATCH')
                    load_good()
                    expect(page.locator('#exactFrame')).to_be_visible()
                    expect(page.locator('#copyReviewReceipt')).to_be_enabled()
                    expect(page.frame_locator('#exactFrame').get_by_role('heading', name='Example Test Workshop')).to_be_visible()
                    assert '555-0100' not in page.frame_locator('#exactFrame').locator('body').inner_text()
                    page.screenshot(path=str(output/'verified-preview.png'), full_page=True)
                    passed('matching recovered preview displayed at mobile viewport; placeholder phone excluded')
                    page.locator('#renderFile').set_input_files({'name':'tampered.html','mimeType':'text/html','buffer':html+b' '})
                    page.locator('#verifyRender').click()
                    expect(page.locator('#renderStatus')).to_contain_text('Rendered HTML digest mismatch')
                    expect(page.locator('#exactFrame')).to_be_hidden()
                    passed('changed HTML rejected by actual browser digest')
                    load_good()
                    page.locator('#business_name').fill('Edited business')
                    expect(page.locator('#exactFrame')).to_be_hidden()
                    expect(page.locator('#copyReviewReceipt')).to_be_disabled()
                    page.locator('#verifyRender').click()
                    expect(page.locator('#renderStatus')).to_contain_text('Admitted DN54 intake JSON must be imported')
                    passed('edit invalidates preview and blocks stale re-verification')
                    assert not errors, errors
                    passed('no uncaught browser page errors')
                    browser.close()
            finally:
                server.shutdown()
                server.server_close()
        results['status'] = 'PASS'
    except Exception as exc:
        results['error'] = str(exc)
        raise
    finally:
        (output/'result.json').write_text(json.dumps(results, indent=2)+'\n')

if __name__ == '__main__':
    main()
