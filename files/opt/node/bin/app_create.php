#!/usr/bin/env php
<?php

require '/opt/node/lib/lib.php';

$app = app_name($argv);
$worker = "$app-www";
$db = "{$app}_main";
$home = "/home/$app";
$myCnf = "$home/.my.cnf";
$appJson = "$home/.app.json";
$php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$site = "/etc/nginx/sites-available/$app.conf";
$siteLink = "/etc/nginx/sites-enabled/$app.conf";
$pool = "/etc/php/$php/fpm/pool.d/$app.conf";
$reloadNginx = false;
$reloadFpm = false;

echo cc("[w]Creating group {$app}[x]\n");
if (posix_getgrnam($app)) {
    echo cc("\t[c]Already exists[x]\n");
} else {
    run('groupadd ' . escapeshellarg($app));
    echo cc("\t[g]Group created[x]\n");
}

echo cc("[w]Creating user {$app}[x]\n");
if (posix_getpwnam($app)) {
    echo cc("\t[c]Already exists[x]\n");
} else {
    run('useradd --create-home --shell /bin/bash --gid ' . escapeshellarg($app) . ' ' . escapeshellarg($app));
    run('chmod 0750 ' . escapeshellarg($home));
    echo cc("\t[g]User created[x]\n");
}

// Written right after the home, so that even a half-created app can be deleted with app_delete.php.
echo cc("[w]Creating {$appJson}[x]\n");
if (is_file($appJson)) {
    echo cc("\t[c]Already exists[x]\n");
} else {
    if (file_put_contents($appJson, json_encode(['app_created_ts' => time()], JSON_PRETTY_PRINT) . "\n") === false || !chmod($appJson, 0644)) {
        fail("Cannot write $appJson");
    }
    echo cc("\t[g]Created[x]\n");
}

echo cc("[w]Creating worker user {$worker}[x]\n");
if (posix_getpwnam($worker)) {
    echo cc("\t[c]Already exists[x]\n");
} else {
    run('useradd --system --no-create-home --shell /usr/sbin/nologin --gid ' . escapeshellarg($app) . ' ' . escapeshellarg($worker));
    echo cc("\t[g]Worker user created[x]\n");
}

// nginx reads the app files through the group. Workers get their groups when they start, hence the reload.
echo cc("[w]Adding www-data to group {$app}[x]\n");
if (in_array('www-data', posix_getgrnam($app)['members'])) {
    echo cc("\t[c]Already a member[x]\n");
} else {
    run('usermod -aG ' . escapeshellarg($app) . ' www-data');
    echo cc("\t[g]Added[x]\n");
    $reloadNginx = true;
}

foreach (['public_html', 'session', 'upload', 'tmp', 'https'] as $dir) {
    $path = "$home/$dir";
    echo cc("[w]Creating directory {$path}[x]\n");
    if (is_dir($path)) {
        echo cc("\t[c]Already exists[x]\n");
        continue;
    }
    if (!mkdir($path) || !chown($path, $app) || !chgrp($path, $app) || !chmod($path, 0770)) {
        fail("Cannot create $path");
    }
    echo cc("\t[g]Directory created[x]\n");
}

// Setgid on directories: files created by any user (app, worker) get the app group.
// chmod runs as the app user: as root, a file swapped for a symlink by the app could redirect it to a system file.
// chown -R doesn't follow symlinks, and makes the app user the owner of everything, so its chmod can't be refused
// (except on a file created by someone else in between: running app_create again fixes it).
// find runs from /: it fails if the app user can't access the working directory (e.g. /root).
$publicHtml = "$home/public_html";
echo cc("[w]Resetting owner and permissions in {$publicHtml}[x]\n");
run('chown -R ' . escapeshellarg("$app:$app") . ' ' . escapeshellarg($publicHtml));
run('cd / && runuser -u ' . escapeshellarg($app) . ' -- find ' . escapeshellarg($publicHtml) . ' -type d -exec chmod 2770 {} + -o -type f -exec chmod 0660 {} +');
echo cc("\t[g]Directories 2770, files 0660, owner {$app}:{$app}[x]\n");

// App configs: only root writes them, the app group can read them.
// The home belongs to the app, which could swap conf for a symlink: only a real directory owned by root is accepted.
$conf = "$home/conf";
echo cc("[w]Creating directory {$conf}[x]\n");
if (!file_exists($conf) && !is_link($conf)) {
    if (!mkdir($conf, 0750)) {
        fail("Cannot create $conf");
    }
    echo cc("\t[g]Directory created[x]\n");
} elseif (is_link($conf) || !is_dir($conf) || fileowner($conf) !== 0) {
    fail("$conf is not a directory owned by root, not touched");
} else {
    echo cc("\t[c]Already exists[x]\n");
}

$domainsFile = "$conf/domains.json";
echo cc("[w]Creating {$domainsFile}[x]\n");
if (is_file($domainsFile)) {
    echo cc("\t[c]Already exists[x]\n");
} else {
    // Edited by hand: pretty printed, without escaped slashes and unicode.
    if (file_put_contents($domainsFile, json_encode([$app], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
        fail("Cannot write $domainsFile");
    }
    echo cc("\t[g]Created with default [\"{$app}\"][x]\n");
}

echo cc("[w]Resetting owner and permissions in {$conf}[x]\n");
foreach (array_merge([$conf], glob("$conf/*")) as $path) {
    if (!chown($path, 'root') || !chgrp($path, $app) || !chmod($path, is_dir($path) ? 0750 : 0640)) {
        fail("Cannot set permissions of $path");
    }
}
echo cc("\t[g]Directories 0750, files 0640, owner root:{$app}[x]\n");

echo cc("[w]Reading {$domainsFile}[x]\n");
$domains = json_decode(file_get_contents($domainsFile), true);
if (!is_array($domains) || !$domains || $domains !== array_values($domains)) {
    fail("$domainsFile must be a non-empty JSON array of domains");
}
foreach ($domains as $domain) {
    // Also keeps nginx syntax out of server_name.
    if (!is_string($domain) || !preg_match('/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*$/i', $domain)) {
        fail("Invalid domain in $domainsFile: " . json_encode($domain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
echo cc("\t[g]Domains: " . implode(' ', $domains) . "[x]\n");

echo cc("[w]Creating database {$db}[x]\n");
if (sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$db'") !== '0') {
    echo cc("\t[c]Already exists[x]\n");
} else {
    sql("CREATE DATABASE `$db`");
    echo cc("\t[g]Database created[x]\n");
}

echo cc("[w]Creating MariaDB user {$app}[x]\n");
if (sql("SELECT COUNT(*) FROM mysql.user WHERE User = '$app' AND Host = 'localhost'") !== '0') {
    echo cc("\t[c]Already exists[x]\n");
    if (!is_file($myCnf)) {
        echo cc("\t[y]$myCnf is missing: password not changed[x]\n");
    } else {
        exec('mysql --defaults-file=' . escapeshellarg($myCnf) . ' -e "SELECT 1" 2>&1', $output, $code);
        if ($code !== 0) {
            fail("Cannot log in to MariaDB with $myCnf: " . implode(' ', $output));
        }
        echo cc("\t[c]Password in {$myCnf} is valid[x]\n");
    }
} else {
    $password = bin2hex(random_bytes(16));
    // .my.cnf is written before creating the user: if writing fails, the next run starts over.
    $oldUmask = umask(0077);
    $written = file_put_contents($myCnf, "[client]\nuser=$app\npassword=$password\n");
    umask($oldUmask);
    if ($written === false || !chown($myCnf, $app) || !chgrp($myCnf, $app) || !chmod($myCnf, 0640)) {
        fail("Cannot write $myCnf");
    }
    sql("CREATE USER '$app'@'localhost' IDENTIFIED BY '$password'");
    echo cc("\t[g]MariaDB user created, password saved in {$myCnf}[x]\n");
}

// "\_" because "_" is a wildcard too: the grant covers every database named <app>_*, including future ones.
echo cc("[w]Granting $app full access to databases {$app}_*[x]\n");
sql("GRANT ALL PRIVILEGES ON `{$app}\\_%`.* TO '$app'@'localhost'");
echo cc("\t[g]Access granted[x]\n");

// Rewritten from the templates on every run: customizations don't go in these files.
foreach ([$site => '/opt/node/tpl/nginx_site.conf', $pool => '/opt/node/tpl/fpm_pool.conf'] as $target => $tpl) {
    echo cc("[w]Writing {$target}[x]\n");
    $template = file_get_contents($tpl);
    if ($template === false) {
        fail("Cannot read $tpl");
    }
    $content = str_replace(['{{appname}}', '{{domains}}'], [$app, implode(' ', $domains)], $template);
    if (is_file($target) && file_get_contents($target) === $content) {
        echo cc("\t[c]Unchanged[x]\n");
        continue;
    }
    if (file_put_contents($target, $content) === false || !chmod($target, 0644)) {
        fail("Cannot write $target");
    }
    echo cc("\t[g]Written[x]\n");
    if ($target === $pool) {
        $reloadFpm = true;
    } else {
        $reloadNginx = true;
    }
}

echo cc("[w]Enabling site {$siteLink}[x]\n");
if (is_link($siteLink) && readlink($siteLink) === $site) {
    echo cc("\t[c]Already enabled[x]\n");
} else {
    if (!symlink($site, $siteLink)) {
        fail("Cannot create $siteLink");
    }
    echo cc("\t[g]Site enabled[x]\n");
    $reloadNginx = true;
}

reload_services($reloadNginx, $reloadFpm);

echo cc("[g]App $app ready[x]\n");
