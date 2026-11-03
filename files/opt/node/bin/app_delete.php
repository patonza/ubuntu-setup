#!/usr/bin/env php
<?php

require '/opt/node/lib/lib.php';

$args = array_values(array_diff($argv, ['--yes']));
$app = app_name($args, ' [--yes]');
$worker = "$app-www";
$home = "/home/$app";
$appJson = "$home/.app.json";
$php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$site = "/etc/nginx/sites-available/$app.conf";
$siteLink = "/etc/nginx/sites-enabled/$app.conf";
$pool = "/etc/php/$php/fpm/pool.d/$app.conf";

$dbs = sql("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '{$app}\\_%'");
$dbs = $dbs === '' ? [] : explode("\n", $dbs);
$dbUser = sql("SELECT COUNT(*) FROM mysql.user WHERE User = '$app' AND Host = 'localhost'") !== '0';

echo cc("[w]Checking app {$app}[x]\n");
if (!$dbs && !$dbUser && !posix_getpwnam($app) && !posix_getpwnam($worker) && !posix_getgrnam($app) && !is_dir($home)
    && !is_link($siteLink) && !file_exists($site) && !file_exists($pool)) {
    echo cc("\t[c]Does not exist, nothing to delete[x]\n");
    exit(0);
}
if (!is_file($appJson)) {
    fail("$appJson is missing: not an app created by app_create.php, nothing deleted");
}
echo cc("\t[g]Found {$appJson}[x]\n");

if (count($args) === count($argv)) {
    // Read from the terminal, not stdin, so that the answer can't come from a pipe by mistake.
    $tty = @fopen('/dev/tty', 'r');
    if (!$tty) {
        fail('No terminal to ask for confirmation: use --yes');
    }
    echo cc("[y]This deletes nginx site, php-fpm pool, users, group, home {$home} and databases (" . ($dbs ? implode(', ', $dbs) : 'none') . ") of {$app}, with all their data.[x]\n");
    echo cc("[y]Type the app name to confirm:[x] ");
    if (trim(fgets($tty)) !== $app) {
        fail('Not confirmed, nothing deleted');
    }
}

// The home, with .app.json, is deleted last: if something fails before, the delete can be run again.

// Configs go first: php-fpm must stop the pool before its worker user can be deleted.
$reloadNginx = false;
$reloadFpm = false;
foreach ([$siteLink, $site, $pool] as $file) {
    echo cc("[w]Deleting {$file}[x]\n");
    if (!is_link($file) && !file_exists($file)) {
        echo cc("\t[c]Does not exist[x]\n");
        continue;
    }
    if (!unlink($file)) {
        fail("Cannot delete $file");
    }
    echo cc("\t[g]Deleted[x]\n");
    if ($file === $pool) {
        $reloadFpm = true;
    } else {
        $reloadNginx = true;
    }
}

// Before the reload, so that nginx workers drop the group (its id may be reused later).
echo cc("[w]Removing www-data from group {$app}[x]\n");
$group = posix_getgrnam($app);
if (!$group || !in_array('www-data', $group['members'])) {
    echo cc("\t[c]Not a member[x]\n");
} else {
    run('gpasswd -d www-data ' . escapeshellarg($app));
    echo cc("\t[g]Removed[x]\n");
    $reloadNginx = true;
}

reload_services($reloadNginx, $reloadFpm);

echo cc("[w]Deleting MariaDB user {$app}[x]\n");
if (!$dbUser) {
    echo cc("\t[c]Does not exist[x]\n");
} else {
    sql("DROP USER '$app'@'localhost'");
    echo cc("\t[g]MariaDB user deleted[x]\n");
}

echo cc("[w]Deleting databases {$app}_*[x]\n");
if (!$dbs) {
    echo cc("\t[c]None found[x]\n");
}
foreach ($dbs as $db) {
    sql("DROP DATABASE `$db`");
    echo cc("\t[g]Deleted {$db}[x]\n");
}

echo cc("[w]Deleting worker user {$worker}[x]\n");
if (!posix_getpwnam($worker)) {
    echo cc("\t[c]Does not exist[x]\n");
} else {
    run('userdel ' . escapeshellarg($worker));
    echo cc("\t[g]Worker user deleted[x]\n");
}

echo cc("[w]Deleting user {$app}[x]\n");
if (!posix_getpwnam($app)) {
    echo cc("\t[c]Does not exist[x]\n");
} else {
    run('userdel ' . escapeshellarg($app));
    echo cc("\t[g]User deleted[x]\n");
}

// userdel may have already deleted the group, if it was the user's own group.
echo cc("[w]Deleting group {$app}[x]\n");
if (!posix_getgrnam($app)) {
    echo cc("\t[c]Does not exist[x]\n");
} else {
    run('groupdel ' . escapeshellarg($app));
    echo cc("\t[g]Group deleted[x]\n");
}

echo cc("[w]Deleting home {$home}[x]\n");
if (!is_dir($home)) {
    echo cc("\t[c]Does not exist[x]\n");
} else {
    run('rm -rf ' . escapeshellarg($home));
    echo cc("\t[g]Home deleted[x]\n");
}

echo cc("[g]App $app deleted[x]\n");
