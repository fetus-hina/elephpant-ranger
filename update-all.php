#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once(__DIR__ . '/script/lib/includes.php');

$skipSnapShots = in_array('--skip', $argv);

$scripts = [
    __DIR__ . '/script/update-php.php',
    // __DIR__ . '/script/update-hhvm.php',
    // __DIR__ . '/script/update-quercus.php',
    // __DIR__ . '/script/update-hippy.php',
    // __DIR__ . '/script/update-pyhp.php',
    // __DIR__ . '/script/update-phpphp.php',
    // __DIR__ . '/script/update-haxe.php',
];
foreach ($scripts as $script) {
    exec_(sprintf(
        '/bin/nice -n 15 %s %s',
        escapeshellarg($script),
        $skipSnapShots ? '--skip' : ''
    ));
}

exec_(sprintf(
    '/usr/bin/rsync -rv %s %s',
    escapeshellarg(__DIR__ . '/cattleshed.conf.d/'),
    escapeshellarg('/opt/wandbox/cattleshed/etc/cattleshed.conf.d/')
));

exec_('sudo /usr/local/bin/restart-cattleshed');
