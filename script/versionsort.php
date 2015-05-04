#!/usr/bin/env hhvm
<?hh // strict
define('REVERSE', in_array('-r', $argv) ? -1 : 1);

$list = [];
while (!feof(STDIN)) {
    $line = trim(fgets(STDIN));
    if ($line !== '') {
        $list[] = $line;
    }
}

usort(
    $list,
    function (string $lhs, string $rhs) : int {
        if ($lhs === 'master') {
            return REVERSE * 1;
        }
        if ($rhs === 'master') {
            return REVERSE * -1;
        }
        if (strpos($lhs, 'snapshot') !== false) {
            $lhs = str_replace('snapshot', '.9999', $lhs);
        }
        if (strpos($rhs, 'snapshot') !== false) {
            $rhs = str_replace('snapshot', '.9999', $rhs);
        }
        return REVERSE * version_compare($lhs, $rhs);
    }
);

if (empty($list)) {
    exit(0);
}

array_walk($list, function (string $line) : void {
    echo $line . "\n";
});