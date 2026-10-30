<?php

$php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

$packages = [
    'nginx',
    'mariadb-server',
    'php-fpm',
    'php-mysql',
    'php-mbstring',
    'php-sqlite3',
    'ssl-cert',
    'sendmail',
];

// Config directory => [check command, apply command]
$services = [
    '/etc/nginx/' => ['nginx -t', 'systemctl reload nginx'],
    "/etc/php/$php/fpm/" => ["php-fpm$php -t", "systemctl reload php$php-fpm"],
    '/etc/mysql/' => [null, 'systemctl restart mariadb'],
    // The umask is inherited at startup: php-fpm's reload keeps the old one.
    "/etc/systemd/system/php$php-fpm.service.d/" => [null, "systemctl daemon-reload && systemctl restart php$php-fpm"],
];

require __DIR__ . '/files/opt/node/lib/lib.php';

// Secrets and private settings stay on the server, never in this repository.
$secretsFile = '/root/.secrets.json';
echo cc("[w]Loading {$secretsFile}[x]\n");
if (!is_file($secretsFile)) {
    fail("$secretsFile is missing");
}
$secrets = json_decode(file_get_contents($secretsFile), true);
if (!is_array($secrets)) {
    fail("$secretsFile is not a valid JSON object: " . json_last_error_msg());
}
echo cc("\t[g]Loaded[x]\n");
if (fileperms($secretsFile) & 0077) {
    echo cc("\t[y]Permissions are " . decoct(fileperms($secretsFile) & 0777) . ": should be 600 (chmod 600 {$secretsFile})[x]\n");
}

// Also needed when setup.php is run by hand, without setup.sh.
putenv('DEBIAN_FRONTEND=noninteractive');

// confdef/confold: keep modified distro configs without asking (there is no terminal to answer).
echo cc("[w]Upgrading system[x]\n");
run('apt-get -o DPkg::Lock::Timeout=120 update');
run('apt-get -o DPkg::Lock::Timeout=120 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold dist-upgrade -y');
echo cc("\t[g]System upgraded[x]\n");

echo cc("[w]Installing packages: " . implode(', ', $packages) . "[x]\n");
run('apt-get -o DPkg::Lock::Timeout=120 install -y ' . implode(' ', array_map('escapeshellarg', $packages)));
echo cc("\t[g]Packages installed[x]\n");

// Copy files/ to /, writing only files whose content or permissions differ from the existing ones.
// Git only tracks the executable bit, so permissions are 0755 for executables and 0644 for the rest,
// 0750 and 0640 in /opt/node, which is only for root.
echo cc("[w]Copying files[x]\n");
$root = __DIR__ . '/files';
$changed = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $target = str_replace('{php}', $php, substr($file->getPathname(), strlen($root)));
    $content = file_get_contents($file->getPathname());
    $private = strpos($target, '/opt/node/') === 0;
    $mode = is_executable($file->getPathname()) ? ($private ? 0750 : 0755) : ($private ? 0640 : 0644);
    $sameContent = is_file($target) && file_get_contents($target) === $content;
    if ($sameContent && (fileperms($target) & 0777) === $mode) {
        echo cc("\t[c]Unchanged {$target}[x]\n");
        continue;
    }

    if (!is_dir(dirname($target)) && !mkdir(dirname($target), $private ? 0750 : 0755, true)) {
        fail("Cannot create " . dirname($target));
    }
    if (!$sameContent && file_put_contents($target, $content) === false) {
        fail("Cannot write $target");
    }
    if (!chmod($target, $mode)) {
        fail("Cannot set permissions of $target");
    }
    echo $sameContent
        ? cc("\t[g]Permissions set to " . decoct($mode) . " {$target}[x]\n")
        : cc("\t[g]Written {$target}[x]\n");

    foreach ($services as $dir => $commands) {
        if (strpos($target, $dir) === 0) {
            $changed[$dir] = $commands;
        }
    }
}

echo cc("[w]Setting permissions of /opt/node directories[x]\n");
$dirs = ['/opt/node'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/opt/node', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
    if ($info->isDir()) {
        $dirs[] = $path;
    }
}
foreach ($dirs as $dir) {
    if ((fileperms($dir) & 0777) === 0750) {
        echo cc("\t[c]Unchanged {$dir}[x]\n");
        continue;
    }
    if (!chmod($dir, 0750)) {
        fail("Cannot set permissions of $dir");
    }
    echo cc("\t[g]Permissions set to 750 {$dir}[x]\n");
}

// Check all configs first, then apply them.
if ($changed) {
    echo cc("[w]Checking and applying changed configs[x]\n");
    foreach ($changed as $commands) {
        if ($commands[0] !== null) {
            run($commands[0]);
        }
    }
    foreach ($changed as $commands) {
        run($commands[1]);
    }
    echo cc("\t[g]Configs applied[x]\n");
}

// /usr/local/sbin is in root's PATH: app_create -> /opt/node/bin/app_create.php
echo cc("[w]Linking commands into /usr/local/sbin[x]\n");
foreach (glob('/opt/node/bin/*.php') as $script) {
    $link = '/usr/local/sbin/' . basename($script, '.php');
    if (is_link($link) && readlink($link) === $script) {
        echo cc("\t[c]Unchanged {$link}[x]\n");
        continue;
    }
    if (file_exists($link) && !is_link($link)) {
        fail("$link exists and is not a link, not replaced");
    }
    if (is_link($link) && !unlink($link)) {
        fail("Cannot remove old link $link");
    }
    if (!symlink($script, $link)) {
        fail("Cannot create $link");
    }
    echo cc("\t[g]Linked {$link}[x]\n");
}

echo cc("[w]Removing unused packages[x]\n");
run('apt-get -o DPkg::Lock::Timeout=120 autoremove -y');
echo cc("\t[g]Unused packages removed[x]\n");

if (file_exists('/var/run/reboot-required')) {
    echo cc("[y]Reboot required to complete the upgrade (e.g. new kernel)[x]\n");
}

echo cc("[g]Setup complete[x]\n");
