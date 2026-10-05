<?php

$php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

$packages = [
    'nginx',
    'mariadb-server',
    'php-fpm',
    'php-mysql',
];

// Directory di configurazione => [comando di verifica, comando per applicare le modifiche]
$services = [
    '/etc/nginx/' => ['nginx -t', 'systemctl reload nginx'],
    "/etc/php/$php/fpm/" => ["php-fpm$php -t", "systemctl reload php$php-fpm"],
    '/etc/mysql/' => [null, 'systemctl restart mariadb'],
];

function fail($message)
{
    fwrite(STDERR, "$message\n");
    exit(1);
}

function run($cmd)
{
    echo "> $cmd\n";
    passthru($cmd, $code);
    if ($code !== 0) {
        fail("Comando fallito (exit $code): $cmd");
    }
}

// Serve anche quando setup.php viene rilanciato a mano, senza passare da setup.sh.
putenv('DEBIAN_FRONTEND=noninteractive');

run('apt-get -o DPkg::Lock::Timeout=120 install -y ' . implode(' ', array_map('escapeshellarg', $packages)));

// Copia files/ a partire da /, scrivendo solo i file diversi da quelli presenti.
$root = __DIR__ . '/files';
$changed = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $target = str_replace('{php}', $php, substr($file->getPathname(), strlen($root)));
    $content = file_get_contents($file->getPathname());
    if (is_file($target) && file_get_contents($target) === $content) {
        continue;
    }

    echo "Scrivo $target\n";
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
        fail("Impossibile creare " . dirname($target));
    }
    if (file_put_contents($target, $content) === false || !chmod($target, 0644)) {
        fail("Impossibile scrivere $target");
    }

    foreach ($services as $dir => $commands) {
        if (strpos($target, $dir) === 0) {
            $changed[$dir] = $commands;
        }
    }
}

// Prima si verificano tutte le configurazioni, poi si applicano.
foreach ($changed as $commands) {
    if ($commands[0] !== null) {
        run($commands[0]);
    }
}
foreach ($changed as $commands) {
    run($commands[1]);
}
