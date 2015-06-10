#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('PHP_PATH', '/opt/wandbin/php/php-head/bin/php'); // TODO: autodetect stable
define('OUTPUT_BASE_DIR', '/opt/wandbin/phpphp');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.phpphp.conf');
define('LOCAL_PATH', __DIR__ . '/../tmp/phpphp');

$skipSnapShots = in_array('--skip', $argv);

// git master HEAD に追従し commit-id を返す
function updateRepository() : ?string
{
    if (file_exists(LOCAL_PATH)) {
        $pushd = pushd(LOCAL_PATH);
        if (!exec_('/usr/bin/git reset --hard') ||
            !exec_('/usr/bin/git clean -xdqf') ||
            !exec_('/usr/bin/git pull origin master -f') ||
            !exec_('/usr/bin/git reset --hard') ||
            !exec_('/usr/bin/git submodule update --init --recursive'))
        {
            return null;
        }
        $lines = [];
        $status = -1;
        exec('/usr/bin/git show -s --format=%H', $lines, $status);
        if ($status !== 0) {
            return null;
        }
        $commitId = array_shift($lines);
        return strtolower(substr($commitId, 0, 7));
    }

    if (!exec_(sprintf(
            '/usr/bin/git clone %s -b master %s --recursive',
            escapeshellarg('https://github.com/ircmaxell/PHPPHP.git'),
            escapeshellarg(LOCAL_PATH)
        )))
    {
        return null;
    }
    pushd(LOCAL_PATH);
    $commitId = exec('/usr/bin/git show -s --format=%H');
    return strtolower(substr($commitId, 0, 7));
}

function updateDepends() : bool
{
    $pushd = pushd(LOCAL_PATH);
    if (!file_exists(LOCAL_PATH . '/composer.phar')) {
        if (!exec_(sprintf(
                '/usr/bin/curl -s %s | php',
                escapeshellarg('https://getcomposer.org/installer')
            )))
        {
            return false;
        }
    } else {
        exec_(sprintf(
            'php %s self-update',
            escapeshellarg(LOCAL_PATH . '/composer.phar')
        ));
    }
    return exec_(sprintf(
            'php %s install --no-dev',
            escapeshellarg(LOCAL_PATH . '/composer.phar')
    ));
}

function getDependsId() : ?string
{
    $pushd = pushd(LOCAL_PATH);
    $json = json_decode(file_get_contents(LOCAL_PATH . '/composer.lock'));
    if (!$json) {
        return null;
    }
    if (!isset($json->packages) || !is_array($json->packages)) {
        return null;
    }
    foreach ($json->packages as $packageInfo) {
        if ($packageInfo->name !== 'nikic/php-parser') {
            continue;
        }
        if (isset($packageInfo->source->reference)) {
            return substr($packageInfo->source->reference, 0, 7);
        }
        if (isset($packageInfo->dist->reference)) {
            return substr($packageInfo->dist->reference, 0, 7);
        }
    }
    return null;
}

function deploy($dst) : bool
{
    $cmdline = sprintf(
        '/usr/bin/rsync -a %s %s',
        escapeshellarg(LOCAL_PATH . '/'),
        escapeshellarg($dst)
    );
    return exec_($cmdline);
}

if (!$commitId = updateRepository()) {
    exit(1);
}
if (!updateDepends()) {
    exit(1);
}
if (!$dependsId = getDependsId()) {
    exit(1);
}

$commitId = sprintf('%s(+%s)', $commitId, $dependsId);

echo "*** CommitID: $commitId\n";
$outputDir = OUTPUT_BASE_DIR . '/phpphp-' . $commitId;
if (file_exists($outputDir . '/php.php'))
{
    echo "*** Already Exists : $commitId\n";
} else {
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    if (!deploy($outputDir)) {
        echo "*** Deploy Failed: $commitId\n";
        exit(1);
    }
}

$json_compilers = [];
$json_compilers[] = [
    'name' => 'phpphp-head',
    'language' => 'PHPPHP',
    'compile-command' => [ '/bin/true' ],
    'output-file' => 'prog.php',
    'displayable' => true,
    'version-command' => [ '/bin/echo', "HEAD ($commitId)" ],
    'run-command' => [
        PHP_PATH,
        "-d date.timezone=UTC",
        "{$outputDir}/php.php",
        'prog.php',
    ],
    'display-compile-command' => 'php.php prog.php',
    'display-name' => 'PHPPHP',
    'runtime-option-raw' => false,
    'switches' => [],
];

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($json_compilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");
