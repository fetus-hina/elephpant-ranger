#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_BASE_DIR', '/opt/hhvm');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.hhvm.conf');
define('LOCAL_PATH', __DIR__ . '/../tmp/hhvm');

$skipSnapShots = in_array('--skip', $argv);

// git master に追従し、任意のブランチ/タグをチェックアウトする
function updateRepository(string $revision = 'master') : bool
{
    if (!file_exists(LOCAL_PATH)) {
        if (!exec_(sprintf(
                '/usr/bin/git clone %s -b master %s',
                escapeshellarg('https://github.com/facebook/hhvm.git'),
                escapeshellarg(LOCAL_PATH)
        )))
        {
            return false;
        }
    }

    $pushd = pushd(LOCAL_PATH);
    if (!exec_('/usr/bin/git reset --hard') ||
        !exec_('/usr/bin/git clean -xdqf') ||
        !exec_(sprintf('/bin/rm -rfv %s', escapeshellarg(LOCAL_PATH . '/third-party'))) ||
        !exec_(sprintf('/usr/bin/git checkout %s -f', escapeshellarg('origin/' . $revision))) ||
        !exec_('/usr/bin/git reset --hard') ||
        !exec_('/usr/bin/git submodule update --init --recursive'))
    {
        return false;
    }
    return true;
}

function getReleaseTags() : ?array<string>
{
    exec(
        sprintf(
            '/usr/bin/git ls-remote --tags %s',
            escapeshellarg('https://github.com/facebook/hhvm.git')
        ),
        $lines,
        $status
    );
    if ($status !== 0) {
        return null;
    }
    return array_values(
        array_filter(
            array_map(
                function (string $v) : ?string {
                    return preg_match('!\brefs/tags/(HHVM-\d+\.\d+\.\d+)$!', $v, $match)
                        ? $match[1]
                        : null;
                },
                $lines
            ),
            function (?string $v) : bool {
                return !is_null($v);
            }
        )
    );
}

function getBuildTargetTags() : ?array<string>
{
    if (!$tags = getReleaseTags()) {
        return null;
    }

    // 各リリースの最終バージョンを取得
    $latest = [];
    foreach ($tags as $tag) {
        if (!preg_match('/^HHVM-((\d+\.\d+)\.\d+)$/', $tag, $match)) {
            continue;
        }
        if (version_compare($match[2], '3.3', '<')) {
            continue;
        }
        if (!isset($latest[$match[2]])) {
            // 同一リリースが見つからなかったので採用
            $latest[$match[2]] = Pair { $match[1], $match[0] };
        } elseif (version_compare($latest[$match[2]][0], $match[1], '<')) {
            // 同一リリース内でより新しいのを見つけたので更新
            $latest[$match[2]] = Pair { $match[1], $match[0] };
        }
    }
    
    // Pair の second に入っている実際のタグ名を返す
    return array_map(
        function (Pair<string, string> $pair) : string {
            return $pair[1];
        },
        array_values($latest)
    );
}

function getMainBranches() : ?array<string>
{
    exec(
        sprintf(
            '/usr/bin/git ls-remote --heads %s',
            escapeshellarg('https://github.com/facebook/hhvm.git')
        ),
        $lines,
        $status
    );
    if ($status !== 0) {
        return null;
    }
    return array_values(
        array_filter(
            array_map(
                function (string $v) : ?string {
                    return preg_match('!\brefs/heads/(HHVM-\d+\.\d+|master)$!', $v, $match)
                        ? $match[1]
                        : null;
                },
                $lines
            ),
            function (?string $v) : bool {
                return !is_null($v);
            }
        )
    );
}

// なんとなくのサポート期限を考慮してなんとなくサポートされていそうな
// 開発ブランチの一覧を取得する
function getActiveBranches() : ?array<string>
{
    if (!$branches = getMainBranches()) {
        return null;
    }

    $releases = [
        '3.3'  => [true,  '2014-09-11'],
        '3.4'  => [false, '2014-11-06'],
        '3.5'  => [false, '2015-01-01'],
        '3.6'  => [true,  '2015-02-26'],
        '3.7'  => [false, '2015-04-23'],
        '3.8'  => [false, '2015-06-18'],
        '3.9'  => [true,  '2015-08-13'],
        '3.10' => [false, '2015-10-08'],
        '3.11' => [false, '2015-12-03'],
        '3.12' => [true,  '2016-01-28'],
        '3.13' => [false, '2016-03-24'],
        '3.14' => [false, '2016-05-19'],
        '3.15' => [true,  '2016-07-14'],
    ];

    return array_values(
        array_filter(
            $branches,
            function (string $name) : bool use ($releases) {
                if ($name === 'master') {
                    return true;
                }
                if (!preg_match('/^HHVM-(\d+\.\d+)$/', $name, $match)) {
                    return false;
                }
                $version = $match[1];
                if (version_compare($version, '3.3', '<')) {
                    return false;
                }
                if (!array_key_exists($version, $releases)) {
                    return true;
                }
                list($isLTS, $releaseDate) = $releases[$version];
                $releaseTime = strtotime($releaseDate . 'T00:00:00+00:00');
                $expiresTime = $releaseTime + 2 * 86400 + ($isLTS ? 48 : 16) * 7 * 86400;
                return time() <= $expiresTime;
            }
        )
    );
}

function getBuildTargets() : ?array<string>
{
    if (!$tags = getBuildTargetTags()) {
        return null;
    }
    if (!$branches = getActiveBranches()) {
        return null;
    }
    return array_merge(
        array_map(
            function (string $tag) : Pair<string, string> {
                return Pair { $tag, strtolower($tag) };
            },
            $tags
        ),
        array_map(
            function (string $tag) : Pair<string, string> {
                return Pair {
                    $tag,
                    $tag === 'master' ? 'hhvm-head' : strtolower($tag) . '-head'
                };
            },
            $branches
        )
    );
}

function buildAndInstall(string $prefix) : bool
{
    $pushd = pushd(LOCAL_PATH);
    if (!exec_(sprintf(
            '/usr/bin/env cmake -DCMAKE_INSTALL_PREFIX=%s -DMYSQL_UNIX_SOCK_ADDR=/dev/null .',
            escapeshellarg($prefix)
    ))) {
        return false;
    }
    
    if (!exec_('/usr/bin/make -j2')) {
        return false;
    }

    if (!exec_(sprintf(
            '/usr/bin/env %s --version',
            escapeshellarg(LOCAL_PATH . '/hphp/hhvm/hhvm')
    ))) {
        return false;
    }

    if (!exec_('/usr/bin/make install')) {
        return false;
    }
    
    return true;
}

if (!$targets = getBuildTargets()) {
    exit(1);
}

foreach ($targets as $targetInfo) {
    $outputDir = OUTPUT_BASE_DIR . '/' . $targetInfo[1];

    if (file_exists($outputDir . '/bin/hhvm')) {
        if (strpos($targetInfo[1], '-head') === false) {
            echo "*** Already Exists: $outputDir\n";
            continue;
        } elseif ($skipSnapShots) {
            echo "*** Skip: $outputDir\n";
            continue;
        }
    }

    echo "*** Build : {$targetInfo[0]}\n";
    if (!updateRepository($targetInfo[0])) {
        exit(1);
    }

    if (!buildAndInstall($outputDir)) {
        exit(1);
    }

    echo "*** Built: {$targetInfo[0]}\n";
}

$jsonCompilers = [];
foreach ($targets as $targetInfo) {
    $outputDir = OUTPUT_BASE_DIR . '/' . $targetInfo[1];
    if (strpos($targetInfo[1], '-head') === false && preg_match('!^HHVM-([\d.]+)$!', $targetInfo[0], $match)) {
        $verCommand = [
            "/bin/echo",
            $match[1],
        ];
        $language = 'HHVM';
    } else {
        $verCommand = [
            "/bin/sh",
            "-c",
            "{$outputDir}/bin/hhvm --version | cut -d' ' -f3",
        ];
        $language = 'HHVM HEADs';
    }

    $jsonCompilers[] = [
        'name' => $targetInfo[1],
        'language' => $language,
        'compile-command' => [
            '/usr/bin/touch',
            '.hhconfig',
        ],
        'output-file' => 'prog.php',
        'displayable' => true,
        'version-command' => $verCommand,
        'run-command' => [
            "{$outputDir}/bin/hhvm",
            "-d date.timezone=Etc/UTC",
            'prog.php',
        ],
        'display-compile-command' => 'hhvm prog.php',
        'display-name' => 'HHVM',
        'runtime-option-raw' => false,
        'switches' => [],
    ];
}

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($jsonCompilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");
