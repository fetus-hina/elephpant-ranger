#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_BASE_DIR', '/opt/hippy');
define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.hippy.conf');
define('RPYTHON_PATH', __DIR__ . '/../opt/pypy/rpython/bin/rpython');
define('LOCAL_PATH', __DIR__ . '/../tmp/hippyvm');

$skipSnapShots = in_array('--skip', $argv);

// git master HEAD に追従し commit-id を返す
function updateRepository() : ?string
{
    if (file_exists(LOCAL_PATH)) {
        $pushd = pushd(LOCAL_PATH);
        if (!exec_('/usr/bin/git reset --hard') ||
            !exec_('/usr/bin/git clean -xdqf') ||
            !exec_('/usr/bin/git pull origin master -f') ||
            !exec_('/usr/bin/git reset --hard'))
        {
            return null;
        }
        $commitId = exec('/usr/bin/git show -s --format=%H');
        return strtolower(substr($commitId, 0, 7));
    }

    if (!exec_(sprintf(
            '/usr/bin/git clone %s -b master %s',
            escapeshellarg('https://github.com/hippyvm/hippyvm.git'),
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
    return exec_('pip install --user -r requirements.txt --user');
}

function build() : bool
{
    $pushd = pushd(LOCAL_PATH);
    $ret = exec_(sprintf(
        '/usr/bin/env %s -Ojit %s',
        escapeshellarg(RPYTHON_PATH),
        escapeshellarg('targethippy.py')
    ));
    if ($ret) {
        $ret = exec_(sprintf(
            '/usr/bin/env %s %s',
            LOCAL_PATH . '/hippy-c',
            __DIR__ . '/hello.php'
        ));
    }
    return !!$ret;
}

if (!$commitId = updateRepository()) {
    exit(1);
}
echo "*** CommitID: $commitId\n";
$outputDir = OUTPUT_BASE_DIR . '/hippy-' . $commitId;
if (file_exists($outputDir . '/hippy-c'))
{
    echo "*** Already Exists : $commitId\n";
} else {
    if (!updateDepends()) {
        exit(1);
    }
    if (!build()) {
        echo "*** Build Failed: $commitId\n";
        exit(1);
    }
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    if (!copy(LOCAL_PATH . '/hippy-c', $outputDir . '/hippy-c')) {
        exit(1);
    }
    chmod($outputDir . '/hippy-c', 0755);
}

$json_compilers = [];
$json_compilers[] = [
    'name' => 'hippy-head',
    'language' => 'HippyVM',
    'compile-command' => [ '/bin/true' ],
    'output-file' => 'prog.php',
    'displayable' => true,
    'version-command' => [ '/bin/echo', "HEAD ($commitId)" ],
    'run-command' => [
        "{$outputDir}/hippy-c",
        'prog.php',
    ],
    'display-compile-command' => 'php prog.php',
    'display-name' => 'HippyVM',
    'runtime-option-raw' => false,
    'switches' => [],
];

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => $json_compilers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");
