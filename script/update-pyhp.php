#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_BASE_DIR', '/opt/pyhp');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.pyhp.conf');
define('RPYTHON_PATH', __DIR__ . '/../opt/pypy/rpython/bin/rpython');
define('LOCAL_PATH', __DIR__ . '/../tmp/pyhp');

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
        $commitId = exec('/usr/bin/git show -s --format=%H');
        return strtolower(substr($commitId, 0, 7));
    }

    if (!exec_(sprintf(
            '/usr/bin/git clone %s -b master %s --recursive',
            escapeshellarg('https://github.com/juokaz/pyhp.git'),
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
    return exec_('pip install -r requirements.txt');
}

function build() : bool
{
    $pushd = pushd(LOCAL_PATH);
    $ret = exec_(sprintf(
        '/usr/bin/env %s -Ojit %s',
        escapeshellarg(RPYTHON_PATH),
        escapeshellarg('targetpyhp.py')
    ));
    if ($ret) {
        $ret = exec_(sprintf(
            '/usr/bin/env %s %s',
            LOCAL_PATH . '/pyhp-c',
            __DIR__ . '/hello.php'
        ));
    }
    return !!$ret;
}

if (!$commitId = updateRepository()) {
    exit(1);
}
echo "*** CommitID: $commitId\n";
$outputDir = OUTPUT_BASE_DIR . '/pyhp-' . $commitId;
if (file_exists($outputDir . '/pyhp-c'))
{
    echo "*** Already Exists : $commitId\n";
} else {
    if (!build()) {
        echo "*** Build Failed: $commitId\n";
        exit(1);
    }
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    if (!copy(LOCAL_PATH . '/pyhp-c', $outputDir . '/pyhp-c')) {
        exit(1);
    }
    chmod($outputDir . '/pyhp-c', 0755);
}

$json_compilers = [];
$json_compilers[] = [
    'name' => 'pyhp-head',
    'language' => 'PyHP',
    'compile-command' => [ '/bin/true' ],
    'output-file' => 'prog.php',
    'displayable' => true,
    'version-command' => [ '/bin/echo', "HEAD ($commitId)" ],
    'run-command' => [
        "{$outputDir}/pyhp-c",
        'prog.php',
    ],
    'display-compile-command' => 'pyhp-c prog.php',
    'display-name' => 'PyHP',
    'runtime-option-raw' => false,
    'switches' => [],
];

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($json_compilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");
