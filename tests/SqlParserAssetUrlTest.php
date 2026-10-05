<?php

/*
 * The SQL Parser page must reference its assets from the mount path the web
 * server reports in SCRIPT_NAME, so it works under an IIS application such as
 * /sqlparser with or without a trailing slash. Page-relative URLs resolved to
 * /assets/* at /sqlparser and were answered by another site as text/html.
 */

function sqlParserAssetAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Render the parser page in an isolated PHP process and return its asset URLs. */
function sqlParserAssetUrls(?string $scriptName): array
{
    $entry = dirname(__DIR__) . '/sqlparser/index.php';
    $program = '$_SERVER["REQUEST_METHOD"] = "GET";'
        . ' $scriptName = getenv("SQLPARSER_TEST_SCRIPT_NAME");'
        . ' if ($scriptName !== false) $_SERVER["SCRIPT_NAME"] = $scriptName; else unset($_SERVER["SCRIPT_NAME"]);'
        . ' include ' . var_export($entry, true) . ';';
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-r', $program);
    $environment = array_merge(is_array(getenv()) ? getenv() : [], ['GENERIC_APP_ENV' => 'development']);
    unset($environment['SQLPARSER_TEST_SCRIPT_NAME']);
    if ($scriptName !== null) $environment['SQLPARSER_TEST_SCRIPT_NAME'] = $scriptName;
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    sqlParserAssetAssert(is_resource($process), 'Unable to render the SQL Parser page.');
    $html = (string)stream_get_contents($pipes[1]);
    $error = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    sqlParserAssetAssert(proc_close($process) === 0, "SQL Parser page rendering failed: {$error}");
    sqlParserAssetAssert(str_contains($html, 'SQL → API JSON Generator'), 'SQL Parser page did not render.');
    preg_match_all('/<link rel="stylesheet" href="([^"]*)">/', $html, $styles);
    preg_match_all('/<script src="([^"]*)"><\/script>/', $html, $scripts);
    preg_match_all('/\b(?:href|src)="([^"]*)"/', $html, $references);
    return ['styles' => $styles[1], 'scripts' => $scripts[1], 'references' => $references[1]];
}

function sqlParserAssetExpect(?string $scriptName, string $base): void
{
    $label = $scriptName === null ? 'unset SCRIPT_NAME' : "SCRIPT_NAME '{$scriptName}'";
    $urls = sqlParserAssetUrls($scriptName);
    sqlParserAssetAssert(
        $urls['styles'] === [$base . '/assets/css/app.css'] && $urls['scripts'] === [$base . '/assets/js/app.js'],
        "{$label} produced " . json_encode($urls, JSON_UNESCAPED_SLASHES) . " instead of {$base}/assets/*."
    );
    foreach ($urls['references'] as $reference) {
        sqlParserAssetAssert(str_starts_with($reference, '/'), "{$label} produced a page-relative URL: {$reference}.");
    }
}

// Mounted under a web-server application path, with or without nesting.
sqlParserAssetExpect('/sqlparser/index.php', '/sqlparser');
sqlParserAssetExpect('\\sqlparser\\index.php', '/sqlparser');
sqlParserAssetExpect('/tools/sql-parser/index.php', '/tools/sql-parser');

// Development launcher and dedicated-listener (root) layouts.
foreach (['/index.php', '/', '', null] as $scriptName) {
    sqlParserAssetExpect($scriptName, '');
}

// Unsafe or unexpected values never reach the page and fall back to /assets.
foreach ([
    '/a/../index.php',
    '/../index.php',
    '/./index.php',
    '/sqlparser/../../index.php',
    '//evil.example/index.php',
    '/x y/index.php',
    '/sqlparser%2F..%2Findex.php',
    '/sqlparser/index.php"><script>alert(1)</script>',
    'sqlparser/index.php',
    '/sqlparser/router.php',
    '/sqlparser/index.php/extra',
] as $scriptName) {
    sqlParserAssetExpect($scriptName, '');
}

$source = (string)file_get_contents(dirname(__DIR__) . '/sqlparser/index.php');
sqlParserAssetAssert(
    !str_contains($source, 'href="assets/') && !str_contains($source, 'src="assets/'),
    'SQL Parser page still contains page-relative asset URLs.'
);

echo "SQL Parser asset URL tests passed.\n";
