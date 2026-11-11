<?php
declare(strict_types=1);

// Installs a temporary failure trigger ONLY in a disposable local hhx_test_*
// database, runs the guarded HTTP smoke, checks the row count, and removes the
// trigger in finally. Never run this on a real database or real installation.
$stage = realpath((string)getenv('HHX_TEST_STAGE_ROOT'));
$temp = realpath(sys_get_temp_dir());
$configPath = realpath((string)getenv('HHX_TEST_CONFIG'));
if (getenv('HHX_ALLOW_TEST_FIXTURES') !== '1' || $stage === false || $temp === false ||
    !str_starts_with($stage, $temp . DIRECTORY_SEPARATOR) ||
    $configPath !== $stage . '/config/config.local.php' ||
    @file_get_contents($stage . '/storage/installed.lock') !== "synthetic-installed-lock\n") {
    fwrite(STDERR, "Disposable temporary stage and explicit test opt-in required.\n"); exit(2);
}
$config = require $configPath;
$db = $config['db'] ?? [];
$name = (string)($db['database'] ?? '');
$host = (string)($db['host'] ?? '127.0.0.1');
if (!preg_match('/\Ahhx_test_[a-z0-9_]+\z/', $name) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "A local hhx_test_* database is required.\n"); exit(2);
}
// Require the same explicit disposable socket that the app tries first; a
// generic localhost/TCP fallback could otherwise target a different server.
$socket = realpath((string)($db['unix_socket'] ?? ''));
if ($socket === false || !str_starts_with($socket, $temp . DIRECTORY_SEPARATOR) || @filetype($socket) !== 'socket') {
    fwrite(STDERR, "An explicit disposable MySQL socket inside the temporary directory is required.\n"); exit(2);
}
$dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=utf8mb4';
$pdo = new PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$engine = $pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
$engine->execute([$name, 'media']);
if (strtoupper((string)$engine->fetchColumn()) !== 'INNODB') throw new RuntimeException('Synthetic media table must use InnoDB.');
function runHttpFixture(array $env): void {
    $process = proc_open(['node', __DIR__ . '/media_upload_http_smoke.mjs'], [0=>['file','/dev/null','r'], 1=>STDOUT, 2=>STDERR], $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Could not start the Node HTTP test.');
    if (proc_close($process) !== 0) throw new RuntimeException('HTTP test did not pass.');
}
$env = getenv();
$env['HHX_TEST_MEDIA_FAULT'] = '';
// Prove the server can upload successfully before injecting the DB failure.
runHttpFixture($env);
$suffix = bin2hex(random_bytes(6));
$trigger = 'hhx_test_media_fail_' . $suffix;
$marker = 'hhx_test_media_marker_' . $suffix;
$countBefore = (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
$triggerCreated = false; $markerCreated = false;
try {
    // A nontransactional marker survives the forced rollback and proves that
    // this particular trigger fired, rather than an unrelated error occurring.
    $pdo->exec("CREATE TABLE `{$marker}` (fired TINYINT NOT NULL) ENGINE=MyISAM");
    $markerCreated = true;
    $markerEngine = $pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $markerEngine->execute([$name, $marker]);
    if (strtoupper((string)$markerEngine->fetchColumn()) !== 'MYISAM') throw new RuntimeException('MyISAM is required for the disposable trigger marker.');
    $pdo->exec("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON media FOR EACH ROW BEGIN INSERT INTO `{$marker}` (fired) VALUES (1); SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic database insertion failure'; END");
    $triggerCreated = true;
    $env['HHX_TEST_MEDIA_FAULT'] = 'db_insert';
    runHttpFixture($env);
    $countAfter = (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
    if ((int)$pdo->query("SELECT COUNT(*) FROM `{$marker}`")->fetchColumn() !== 1) throw new RuntimeException('The synthetic failure trigger did not fire exactly once.');
    if ($countBefore !== $countAfter) throw new RuntimeException('Failed upload unexpectedly changed the media row count.');
    echo "PASS: the synthetic trigger fired once and the failed insert left the media row count unchanged.\n";
} finally {
    try { if ($triggerCreated) $pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`"); }
    finally { if ($markerCreated) $pdo->exec("DROP TABLE IF EXISTS `{$marker}`"); }
}
