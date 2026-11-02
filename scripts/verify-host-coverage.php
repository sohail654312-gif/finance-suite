<?php

declare(strict_types=1);

/** Independent fail-closed check of the unchanged 100% app/ line-coverage gate. */
$path = $argv[1] ?? 'coverage.xml';
libxml_use_internal_errors(true);
$report = is_file($path) ? simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET) : false;
$metrics = $report === false ? [] : $report->xpath('/coverage/project/metrics');

if (count($metrics) !== 1) {
    fwrite(STDERR, "FAIL: missing or invalid host coverage report.\n");
    exit(1);
}

$statements = (string) $metrics[0]['statements'];
$covered = (string) $metrics[0]['coveredstatements'];
if (! ctype_digit($statements) || ! ctype_digit($covered) || (int) $statements < 1 || (int) $covered > (int) $statements) {
    fwrite(STDERR, "FAIL: invalid host coverage counts.\n");
    exit(1);
}

$total = (int) $statements;
$executed = (int) $covered;
printf("Host coverage: %d / %d executable lines (%.4f%%); required: 100%%.\n", $executed, $total, 100 * $executed / $total);

foreach ($report->xpath('//file') ?: [] as $file) {
    $missing = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (string) $line['count'] === '0') {
            $missing[] = (string) $line['num'];
        }
    }
    if ($missing !== []) {
        printf("UNCOVERED %s: %s\n", (string) $file['name'], implode(',', $missing));
    }
}

exit($executed === $total ? 0 : 1);
