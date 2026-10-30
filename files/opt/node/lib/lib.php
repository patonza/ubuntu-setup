<?php

function _c($c=null) {
  $c = ['red'=>'38;2;255;95;95','green'=>'38;2;92;184;92','yellow'=>'38;2;242;193;78','cyan'=>'38;2;56;170;204','gray'=>'38;2;88;94;104','blue'=>'38;2;123;140;255','magenta'=>'38;2;255;79;216','white'=>'38;2;232;232;232','lightgray'=>'38;2;180;170;200'][(string) $c] ?? '38;2;168;176;186';
  return "\x1b[{$c}m";
}

function c($c=null) {
  echo _c($c);
}

function cc($str) {
  $out = preg_replace_callback('#\[(?<c>[rgyckbmwlx])\]#', function($m){
    $map = [ 'r'=>'red','g'=>'green','y'=>'yellow','c'=>'cyan','k'=>'gray','b'=>'blue','m'=>'magenta','w'=>'white','l'=>'lightgray','x'=>null ];
    $c = $map[$m['c']];
    return _c($c);
  }, $str);
  return $out;
}

function fail($message)
{
    fwrite(STDERR, cc("\t[r]{$message}[x]\n"));
    exit(1);
}

function run($cmd)
{
    echo cc("[l]> {$cmd}[x]\n");
    c('gray');
    passthru($cmd, $code);
    c();
    if ($code !== 0) {
        fail("Command failed (exit $code): $cmd");
    }
}

// Queries go through stdin so that passwords don't show up in the process list.
function sql($query)
{
    $process = proc_open('mysql -N -B 2>&1', [['pipe', 'r'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $query);
    fclose($pipes[0]);
    $output = trim(stream_get_contents($pipes[1]));
    if (proc_close($process) !== 0) {
        fail("MariaDB query failed: $output");
    }
    return $output;
}

// Returns the app name given as first argument, or exits with the usage message.
function app_name($argv, $options = '')
{
    $app = $argv[1] ?? '';
    if (!preg_match('/^[a-z][a-z0-9]{0,15}$/', $app)) {
        fail('Usage: ' . basename($argv[0]) . ' <name>' . $options . ' (lowercase letters and digits, starting with a letter, max 16 chars)');
    }
    return $app;
}

// Checks the changed configs first, then reloads; php-fpm goes first so that app sockets exist when nginx uses them.
function reload_services($nginx, $fpm)
{
    if (!$nginx && !$fpm) {
        return;
    }
    $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    echo cc("[w]Checking and applying configs[x]\n");
    if ($fpm) {
        run("php-fpm$php -t");
    }
    if ($nginx) {
        run('nginx -t');
    }
    if ($fpm) {
        run("systemctl reload php$php-fpm");
    }
    if ($nginx) {
        run('systemctl reload nginx');
    }
    echo cc("\t[g]Configs applied[x]\n");
}
