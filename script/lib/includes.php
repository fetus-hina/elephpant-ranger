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
