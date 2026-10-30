"""Build a closed Hostinger candidate from synthetic files; no database or SMS."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
BUILDER = ROOT / 'scripts/build-hostinger-package.py'
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise RuntimeError(label)
    checks += 1


with tempfile.TemporaryDirectory(prefix='bcihs-package-qa-') as folder:
    folder = Path(folder)
    workspace = folder / 'private-staging'
    workspace.mkdir(mode=0o700)
    manifest = json.loads((ROOT / 'demo-assets/manifest.json').read_text())
    assets = [row['image_path'] for row in manifest['books'] + manifest['announcements']]
    for name in assets:
        target = workspace / 'app' / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / name, target)
    (workspace / 'app/demo-assets/manifest.json').write_text(json.dumps(manifest))

    release = folder / 'release'
    release.mkdir(mode=0o700)
    vendor = folder / 'vendor'
    vendor.mkdir(mode=0o700)
    (vendor / 'autoload.php').write_text('<?php // synthetic dependency stub for package-path test\n')
    sql = b'-- Synthetic package checksum fixture; contains no database rows.\n'
    (release / 'bcihs-refined.sql').write_bytes(sql)
    record = {
        'source_database': 'bcihs_demo_test_00000000',
        'restored_row_hashes_match': True,
        'sql_sha256': hashlib.sha256(sql).hexdigest(),
    }
    (release / 'database-verification.json').write_text(json.dumps(record))

    result = subprocess.run(
        [sys.executable, str(BUILDER), str(release), '--staging', str(workspace), '--vendor-dir', str(vendor)],
        capture_output=True,
    )
    if result.returncode != 0:
        detail = result.stderr.decode(errors='replace').replace(str(ROOT), '[repository]').replace(str(folder), '[temporary directory]')
        lines = detail.splitlines()
        summary = lines[-1] if lines else 'unknown build error'
        raise RuntimeError('Synthetic package build failed: ' + summary)

    public = release / 'application/public_html'
    private = release / 'application/bcihs-private'
    check((public / 'favicon.ico').read_bytes() == (ROOT / 'favicon.ico').read_bytes(), 'School icon copied')
    check((private / 'vendor/autoload.php').read_text() == (vendor / 'autoload.php').read_text(), 'Explicit Composer directory copied outside web root')
    check('Require all denied' in (public / '.htaccess').read_text(), 'Public access remains denied by default')
    check((public / 'includes/.htaccess').read_text().strip() == 'Require all denied', 'Internal includes remain blocked')
    check("'live_enabled'=>false" in (public / 'includes/sms-local.php').read_text(), 'SMS remains simulation-only')
    check(not (private / 'config.php').exists() and not (private / 'activation.json').exists(), 'No private credentials or activation marker copied')
    check(all((public / name).is_file() for name in assets), 'All approved book and announcement artwork copied')
    check('hostingRequireRotation();' in (public / 'includes/session.php').read_text(), 'Server-side activation gate retained')
    before = (release / 'application-manifest.json').read_bytes()
    repeat = subprocess.run(
        [sys.executable, str(BUILDER), str(release), '--staging', str(workspace), '--vendor-dir', str(vendor)],
        capture_output=True,
    )
    check(repeat.returncode != 0 and before == (release / 'application-manifest.json').read_bytes(), 'Existing package is not overwritten')

    missing = folder / 'unconfigured-release'
    missing.mkdir()
    env = dict(os.environ)
    env.pop('BCIHS_STAGING_WORKSPACE', None)
    refused = subprocess.run([sys.executable, str(BUILDER), str(missing)], env=env, capture_output=True)
    check(refused.returncode != 0 and not list(missing.iterdir()), 'Missing private staging configuration creates nothing')

print(f'Passed {checks} synthetic package checks; no database or provider calls.')
