<?php
// Exercise path selection only; never connect to a database or run mysqldump.
$source = file_get_contents(__DIR__ . '/../scripts/local-backup.php');
$source = str_replace("require_once __DIR__ . '/local-cli.php';", '', $source, $removed);
if ($removed !== 1) throw new RuntimeException('Backup helper isolation did not match.');
eval(substr($source, 5));
$checks = 0;
function backupPathCheck(bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('Portable backup path check failed.');
    $checks++;
}
$directory = sys_get_temp_dir() . '/bcihs-backup-path-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
try {
    putenv('BCIHS_BACKUP_DIR');
    try { localBackupDirectory(); throw new LogicException('Missing path was accepted.'); }
    catch (RuntimeException $error) { backupPathCheck(str_contains($error->getMessage(), 'BCIHS_BACKUP_DIR')); }
    putenv('BCIHS_BACKUP_DIR=' . $directory);
    backupPathCheck(localBackupDirectory() === $directory);
    putenv('BCIHS_MYSQLDUMP=' . $directory . '/configured-dump');
    backupPathCheck(localDumpTool() === $directory . '/configured-dump');
    putenv('BCIHS_MYSQLDUMP');
    putenv('PATH=' . $directory);
    try { localDumpTool(); throw new LogicException('Missing executable was accepted.'); }
    catch (RuntimeException $error) { backupPathCheck(str_contains($error->getMessage(), 'BCIHS_MYSQLDUMP')); }
    $file = $directory . (PHP_OS_FAMILY === 'Windows' ? '/mysqldump.exe' : '/mysqldump');
    file_put_contents($file, 'Synthetic executable-path fixture; never run.');
    chmod($file, 0700);
    backupPathCheck(localDumpTool() === $file);
    backupPathCheck(count(glob($directory . '/*')) === 1);
    echo "Passed $checks portable backup path checks; no database or dump process.\n";
} finally {
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
