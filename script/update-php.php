#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_BASE_DIR', '/opt/wandbin/php');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.php.conf');

$skipSnapShots = in_array('--skip', $argv);

// 指定されたバージョンに必要な追加パッチのリストを返す
function getPatches(string $version): array
{
    $patches = [];
    if (version_compare($version, '5.4.7', '<')) {
        $patches[] = 'php_dom.patch';
    }
    return $patches;
}

// libstdc++ を明示的にリンクしなければならないバージョンか判定する
// C++ ソースを利用しているのに libstdc++ をリンクしないバージョンが
// 存在する
function fixLinkCPlusPlus(string $version): bool
{
    return version_compare($version, '5.3.0', '>=') &&
        version_compare($version, '5.4.0', '<');
}

function beDisableZip(string $version): bool
{
    return version_compare($version, '7.3.9999', '>');
}

// php-build で作成可能な PHP バージョンの一覧を取得する
function getPhpVersionList(): array
{
    $cmdline = sprintf(
        '/usr/bin/env %s --definitions | %s',
        escapeshellarg('/opt/ranger/php-build/bin/php-build'),
        escapeshellarg(__DIR__ . '/versionsort.php')
    );
    exec($cmdline, $lines, $status);
    if ($status !== 0) {
        fwrite(STDERR, "Could not get version list from php-build\n");
        exit(1);
    }
    return array_map(
        function (string $a) : string {
            return trim($a);
        },
        $lines
    );
}

// 一部のバージョンはコンパイル対象から外す
function isIgnoreVersion(string $version): bool
{
    $list = [
        '5.4snapshot',
        '5.5snapshot',
        '5.6.0RC1',
        '5.6.0RC2',
        '5.6.0RC3',
        '5.6.0RC4',
        '5.6snapshot',
        '7.0.0RC1',
        '7.0.0RC2',
        '7.0.0RC3',
        '7.0.0RC4',
        '7.0.0RC5',
        '7.0.0RC6',
        '7.0.0RC7',
        '7.0.0RC8',
        '7.0.0alpha1',
        '7.0.0alpha2',
        '7.0.0beta1',
        '7.0.0beta2',
        '7.0.0beta3',
        '7.0snapshot',
        '7.1.0beta1',
        '7.1.0beta2',
        '7.1.0beta3',
        '7.1snapshot',
        '7.3.0RC1',
        '7.3.0RC2',
        '7.3.0RC3',
        '7.3.0RC4',
        '7.3.0RC5',
        '7.3.0alpha1',
        '7.3.0alpha2',
        '7.3.0alpha3',
        '7.3.0alpha4',
        '7.3.0beta1',
        '7.3.0beta2',
        '7.3.0beta3',
        '7.3.2RC1',
    ];
    return !!in_array($version, $list, true);
}

$json_switches = [];
$json_compilers = [];
$fails = [];
foreach (getPhpVersionList() as $version) {
    if (isIgnoreVersion($version)) {
        echo "Skip: {$version}\n";
        continue;
    }
    $force = false;
    if (preg_match('/^[\d.]+(?:(?:alpha|beta|RC)\d+)?$/', $version)) { // release
        $outdir = OUTPUT_BASE_DIR . '/php-' . strtolower($version);
        $wandbox_category = 'PHP ' . preg_replace('/^(\d+\.\d+).+$/', "$1", $version);
    } elseif (strpos($version, 'snapshot') !== false) {
        $outdir = sprintf(
            '%s/php-%s-head',
            OUTPUT_BASE_DIR,
            preg_replace('/snapshot$/', '', $version)
        );
        $wandbox_category = 'PHP HEADs';
        $force = !$skipSnapShots;
    } elseif ($version === 'master') {
        $outdir = OUTPUT_BASE_DIR . '/php-head';
        $wandbox_category = 'PHP HEADs';
        $force = !$skipSnapShots;
    } else {
        continue;
    }

    if (!$force && file_exists($outdir . '/bin/php')) {
        echo "Already exists: $version\n";
    } else { 
        echo "**** BUILDING PHP {$version} ****\n";

        if ($patches = getPatches($version)) {
            foreach (array_reverse($patches) as $patch) {
                addSourcePatchSetting($version, $patch);
            }
        }

        if (beDisableZip($version)) {
            disableZip($version);
        }

        $cmdline = sprintf(
            '/usr/bin/env CFLAGS=%1$s CXXFLAGS=%1$s %4$s scl enable devtoolset-9 -- /opt/ranger/php-build/bin/php-build --verbose %2$s %3$s',
            escapeshellarg(implode(' ', array_filter([
                '-O3',
                '-march=native',
                '-mtune=native',
            ]))),
            escapeshellarg($version),
            escapeshellarg($outdir),
            implode(' ', array_filter([
                fixLinkCPlusPlus($version)
                    ? 'EXTRA_LIBS=' . escapeshellarg('-lstdc++')
                    : null,
            ])),
        );
        echo str_repeat('-', 72) . "\n";
        echo $cmdline . "\n";
        echo str_repeat('-', 72) . "\n";
        passthru($cmdline, $status);
        if ($status != 0) {
            echo "**** PHP {$version} failed building ****\n";
            $fails[] = $version;
            continue;
        }
    }

    $dispVersion = exec(
        sprintf(
            '/usr/bin/env %s -v | head -1 | cut -d" " -f 2',
            "{$outdir}/bin/php",
            ' '
        )
    );

    $json_compilers[] = [
        'name' => basename($outdir),
        'language' => $wandbox_category,
        'compile-command' => [ '/bin/true' ],
        'output-file' => 'prog.php',
        'displayable' => true,
        'version-command' => [ '/bin/echo', $dispVersion ],
        'run-command' => [
            "{$outdir}/bin/php",
            "-d date.timezone=UTC",
            'prog.php',
        ],
        'display-compile-command' => 'php prog.php',
        'display-name' => 'PHP',
        'runtime-option-raw' => false,
        'switches' => [],
    ];
}

if ($fails) {
    echo "********************************\n";
    echo "FAILED:\n";
    echo implode("\n", $fails) . "\n";
    echo "********************************\n";
}

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($json_compilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");

function addSourcePatchSetting(string $version, string $patch): void
{
    $confPath = '/opt/ranger/php-build/share/php-build/definitions/' . $version;
    $confLine = "patch_file \"{$patch}\"";
    $conf = file_get_contents($confPath);
    if (preg_match('/^' . preg_quote($confLine, '$/') . '/m', $conf)) {
        echo "Source patch {$patch} already configured\n";
        return;
    }
    $conf = preg_replace('/^(?=install_package)/m', $confLine . "\n", $conf);
    file_put_contents($confPath, $conf);
}

function disableZip(string $version): void
{
    $delLine = 'configure_option "--with-zip"';
    $addLine = 'configure_option -D "--with-zip"';

    $confPath = '/opt/ranger/php-build/share/php-build/definitions/' . $version;
    $conf = file_get_contents($confPath);
    $origConf = $conf;

    if (preg_match('/^' . preg_quote($delLine, '/') . '$/m', $conf)) {
        $conf = preg_replace(
            '/^' . preg_quote($delLine, '/') . '$/m',
            '',
            $conf
        );
    }

    if (!preg_match('/^' . preg_quote($addLine, '/') . '$/m', $conf)) {
        $conf = preg_replace('/^(?=install_package)/m', $addLine . "\n", $conf);
    }

    if ($conf !== $origConf) {
        file_put_contents($confPath, $conf);
    }
}
