<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

it('publishes both supported version aliases from the real image metadata step', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/docker.yml'));
    $step = collect($workflow['jobs']['build']['steps'])->firstWhere('name', 'Extract image metadata');
    $output = tempnam(sys_get_temp_dir(), 'finance-image-');
    try {
        $process = new Process(['bash', '-c', $step['run']], base_path(), [
            'EVENT_NAME' => 'push', 'PR_NUMBER' => '', 'REF_NAME' => 'v1.2.3', 'REF_TYPE' => 'tag',
            'GITHUB_REPOSITORY' => 'Synthetic/Finance-Suite', 'GITHUB_OUTPUT' => $output,
        ]);
        $process->mustRun();
        expect(file_get_contents($output))->toContain('ghcr.io/synthetic/finance-suite:v1.2.3')
            ->toContain('ghcr.io/synthetic/finance-suite:1.2.3');
    } finally {
        unlink($output);
    }
});

it('renders the selected release without applying example secrets and fails closed on deployment errors', function (string $renderFailure, string $waitFailure, int $exitCode): void {
    $directory = sys_get_temp_dir().'/finance-deploy-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $mock = $directory.'/kubectl';
    $manifest = $directory.'/manifest.yaml';
    $commands = $directory.'/commands.txt';
    $lookup = new Process(['bash', '-c', 'command -v kubectl'], base_path());
    $lookup->mustRun();
    file_put_contents($mock, <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >> "$CAPTURE_COMMANDS"
        case "$1" in
          create) printf 'kind: List\nitems: []\n' ;;
          kustomize)
            if [ "$FAIL_RENDER" = '1' ]; then exit 42; fi
            "$REAL_KUBECTL" "$@" ;;
          apply) cat >> "$CAPTURE_MANIFEST" ;;
          wait) exit "$FAIL_WAIT" ;;
          *) exit 87 ;;
        esac
        BASH
    );
    chmod($mock, 0700);
    try {
        $process = new Process(['bash', base_path('k8s/deploy.sh')], base_path(), [
            'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
            'REAL_KUBECTL' => trim($lookup->getOutput()),
            'CAPTURE_COMMANDS' => $commands, 'CAPTURE_MANIFEST' => $manifest,
            'FAIL_RENDER' => $renderFailure, 'FAIL_WAIT' => $waitFailure,
            'ENVIRONMENT' => 'production', 'IMAGE_TAG' => 'v1.2.3',
            'IMAGE_REPOSITORY' => 'ghcr.io/synthetic/finance-suite',
            'APP_KEY' => 'synthetic-test-key', 'DB_PASSWORD' => 'synthetic-test-password',
            'DB_ROOT_PASSWORD' => 'synthetic-test-root-password',
        ]);
        $process->run();
        expect($process->getExitCode())->toBe($exitCode, $process->getErrorOutput());
        expect(file_get_contents($commands))->toContain('create secret generic accounting-erp-secrets');
        if ($renderFailure === '0') {
            expect(file_get_contents($manifest))->toContain('image: ghcr.io/synthetic/finance-suite:v1.2.3')
                ->not->toContain('image: ghcr.io/liberusoftware/accounting-erp-laravel:')
                ->not->toContain('kind: Secret')
                ->not->toContain('REPLACE_WITH_');
        }
        if ($exitCode === 0) {
            expect($process->getOutput())->toContain('Deployment complete!');
        } else {
            expect($process->getOutput())->not->toContain('Deployment complete!');
        }
    } finally {
        foreach ([$mock, $manifest, $commands] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);
    }
})->with([
    'successful synthetic deployment' => ['0', '0', 0],
    'manifest render failure' => ['1', '0', 42],
    'rollout readiness failure' => ['0', '43', 43],
]);
