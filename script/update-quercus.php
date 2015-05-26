#!/usr/bin/env hhvm
<?hh // strict
require_once(__DIR__ . '/lib/includes.php');

define('OUTPUT_COMPILERS_LIST', __DIR__ . '/../cattleshed.conf.d/compilers.quercus.conf');

$jsonCompilers = [];
$jsonCompilers[] = [
    'name' => 'quercus-4.0.39',
    'language' => 'Quercus',
    'compile-command' => [ '/bin/true' ],
    'output-file' => 'prog.php',
    'displayable' => true,
    "jail-name" => "jvm",
    'version-command' => [
        '/bin/echo',
        '4.0.39',
    ],
    'run-command' => [
        "/usr/bin/java",
        "-jar",
        "/opt/wandbin/quercus/quercus-4.0.39/WEB-INF/lib/quercus.jar",
        "prog.php",
    ],
    "display-compile-command" => "java -jar quercus.jar prog.php",
    "display-name" => "Quercus",
    'runtime-option-raw' => false,
    'switches' => [],
];

$json = json_encode([
    'switches' => new \stdClass(),
    'compilers' => sortCompilers($jsonCompilers),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents(OUTPUT_COMPILERS_LIST, $json . "\n");
