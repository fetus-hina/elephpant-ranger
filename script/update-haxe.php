#!/usr/bin/env php
<?php
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.haxe.conf');

$phpVersions = getPhpVersions('/opt/wandbin/php');

$switches = makeSwithces($phpVersions);
$json = [];
foreach (getHaxeVersions('/opt/haxe') as $haxeVersion) {
    $json[] = [
        'name' => sprintf('Haxe %s', $haxeVersion),
        'language' => 'Haxe+PHP',
        'compile-command' => [
            '/usr/bin/scl',
            'enable',
            'php70',
            '--',
            '/opt/wandbin/haxe/compile.php',
            sprintf('--haxe=%s', $haxeVersion),
            '--main=Main',
            '--out=compiled',
        ],
        'output-file' => 'Main.hx',
        'displayable' => true,
        'version-command' => [ '/bin/echo', $haxeVersion ],
        'run-command' => [
            '/usr/bin/scl',
            'enable',
            'php70',
            '--',
            '/opt/wandbin/haxe/runner.php',
            '--dir=compiled',
            '--main=index',
        ],
        'display-compile-command' => 'haxe -main Main -php compiled',
        'display-name' => 'Haxe',
        'runtime-option-raw' => false,
        'switches' => array_keys($switches),
    ];
}

$json = json_encode([
    'switches' => (object)$switches,
    'compilers' => $json,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");

function getHaxeVersions($base)
{
    $versions = [];
    foreach (new DirectoryIterator($base) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) {
            continue;
        }
        if (preg_match('/^haxe-([\d.]+(?:-(?:alpha|beta|rc)\d+)?)$/', $entry->getBasename(), $match)) {
            $versions[] = $match[1];
        }
    }
    usort($versions, 'version_compare');
    return array_reverse($versions);
}

function getPhpVersions($base)
{
    $majorVersions = [];
    foreach (new DirectoryIterator($base) as $entry) {
        if ($entry->isDot() || !$entry->isDir()) {
            continue;
        }
        if (preg_match('/^php-((\d+\.\d+)\.\d+(?:(?:alpha|beta|rc)\d+)?)$/', $entry->getBasename(), $match)) {
            $version = $match[1];
            $major = $match[2];
            if (!isset($majorVersions[$major])) {
                $majorVersions[$major] = [];
            }
             $majorVersions[$major][] = $version;
        }
    }

    $ret = [];
    foreach ($majorVersions as $versions) {
        usort($versions, 'version_compare');
        $ret[] = array_pop($versions);
    }
    $ret[] = 'head';
    return array_reverse($ret);
}

function makeSwithces(array $phpVersions)
{
    $keys = array_map(function ($v) {
        return sprintf('haxe-php-%s', $v);
    }, $phpVersions);

    $ret = [];
    foreach ($phpVersions as $version) {
        $key = sprintf('haxe-php-%s', $version);
        $ret[$key] = (object)[
            'conflicts' => array_values(array_filter($keys, function ($v) use ($key) {
                return $v !== $key;
            })),
            'display-flags' => '',
            'display-name' => sprintf('PHP %s', $version),
            'flags' => sprintf('--php=%s', $version),
            'runtime' => true,
        ];
    }
    return $ret;
}
