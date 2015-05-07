<?hh // struct
require_once(__DIR__ . '/PushD.php');

function pushd(string $newDir) : PushD
{
    return new PushD($newDir);
}

function exec_(string $cmdline) : bool
{
    echo "EXECUTE: {$cmdline}\n";
    $status = null;
    passthru($cmdline, $status);
    echo "STATUS: {$status}\n";
    return $status === 0;
}

function sortCompilers(array $compilers) : array
{
    usort(
        $compilers,
        function ($lhs, $rhs) : int {
            if (($tmp = strnatcasecmp($lhs['display-name'], $rhs['display-name'])) !== 0) {
                return $tmp;
            }
            if (($tmp = strnatcasecmp($lhs['language'], $rhs['language'])) !== 0) {
                return $tmp;
            }
            if ($lhs['name'] === $rhs['name']) {
                return 0;
            }
            return -1 * strnatcasecmp($lhs['name'], $rhs['name']);
        }
    );
    return $compilers;
}
