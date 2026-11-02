<?php

use Symfony\Component\Process\Process;

it('enforces exact host coverage from clover counts without rounding or empty-report passes', function (string $xml, int $expectedExit): void {
    $report = tempnam(sys_get_temp_dir(), 'host-coverage-');
    try {
        file_put_contents($report, $xml);
        $process = new Process([PHP_BINARY, base_path('scripts/verify-host-coverage.php'), $report]);
        $process->run();
        expect($process->getExitCode())->toBe($expectedExit);
    } finally {
        unlink($report);
    }
})->with([
    'exactly complete' => ['<coverage><project><metrics statements="10" coveredstatements="10"/></project></coverage>', 0],
    'one missed line' => ['<coverage><project><metrics statements="10" coveredstatements="9"/></project></coverage>', 1],
    'rounds to 100 but incomplete' => ['<coverage><project><metrics statements="100000" coveredstatements="99999"/></project></coverage>', 1],
    'empty source' => ['<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>', 1],
    'missing metrics' => ['<coverage><project/></coverage>', 1],
    'malformed XML' => ['<coverage>', 1],
    'impossible counts' => ['<coverage><project><metrics statements="10" coveredstatements="11"/></project></coverage>', 1],
    'non-numeric counts' => ['<coverage><project><metrics statements="ten" coveredstatements="ten"/></project></coverage>', 1],
]);
