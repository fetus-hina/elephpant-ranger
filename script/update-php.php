#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_BASE_DIR', '/opt/php');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.php.conf');

$skipSnapShots = in_array('--skip', $argv);

// 指定されたバージョンに必要な追加パッチのリストを返す
function getPatches(string $version) : array<string>
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
function fixLinkCPlusPlus(string $version) : bool
{
    return version_compare($version, '5.3.0', '>=') &&
        version_compare($version, '5.4.0', '<');
}

// php-build で作成可能な PHP バージョンの一覧を取得する
function getPhpVersionList() : array<string>
{
    $cmdline = sprintf(
        '/usr/bin/env %s --definitions | %s',
        escapeshellarg('php-build'),
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
function isIgnoreVersion(string $version) : bool
{
    $list = [
        '5.4snapshot',
    ];
    return !!in_array($version, $list);
}

$json_switches = [];
$json_compilers = [];
foreach (getPhpVersionList() as $version) {
    if (isIgnoreVersion($version)) {
        echo "Skip: {$version}\n";
        continue;
    }
    $force = false;
    if (preg_match('/^[\d.]+$/', $version)) { // release
        $outdir = OUTPUT_BASE_DIR . '/php-' . $version;
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
    $force = false;

    if (!$force && file_exists($outdir . '/bin/php')) {
        echo "Already exists: $version\n";
    } else { 
        echo "**** BUILDING PHP {$version} ****\n";

        if ($patches = getPatches($version)) {
            foreach (array_reverse($patches) as $patch) {
                addSourcePatchSetting($version, $patch);
            }
        }
        $cmdline = sprintf(
            '/usr/bin/env CFLAGS=%1$s CXXFLAGS=%1$s %4$s php-build %2$s %3$s',
            escapeshellarg('-O3 -march=native -mtune=native'),
            escapeshellarg($version),
            escapeshellarg($outdir),
            fixLinkCPlusPlus($version)
                ? 'EXTRA_LIBS=' . escapeshellarg('-lstdc++')
                : ''
        );
        passthru($cmdline, $status);
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

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($json_compilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");

function addSourcePatchSetting($version, $patch)
{
    $confPath = '/usr/local/share/php-build/definitions/' . $version;
    $confLine = "patch_file \"{$patch}\"";
    $conf = file_get_contents($confPath);
    if (preg_match('/^' . preg_quote($confLine, '$/') . '/m', $conf)) {
        echo "Source patch {$patch} already configured\n";
        return;
    }
    $conf = preg_replace('/^(?=install_package)/m', $confLine . "\n", $conf);
    file_put_contents($confPath, $conf);
}
