<?php
declare(strict_types=1);

$configurationPathAndFilename = __DIR__ . '/config.json';

$gitSubsplitBinary = '"' . __DIR__ . '/git-subsplit/git-subsplit.sh"';
$gitSubsplitBinaryForNeosFusionAfx = '"' . __DIR__ . '/git-subsplit-with-ignore-joins.sh"';

function processPayload(string $configurationPathAndFilename, array $argv): array
{
    if (!file_exists($configurationPathAndFilename)) {
        echo 'Skipping request (config.json does not exist)' . PHP_EOL;
        exit(1);
    }

    $config = json_decode(file_get_contents($configurationPathAndFilename), true, 512, JSON_THROW_ON_ERROR);

    $data = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($data)) {
        echo sprintf('Skipping request (could not decode payload: %s)', $argv[1]) . PHP_EOL;
        exit(0);
    }

    $name = null;
    $project = [];
    foreach ($config['projects'] as $projectName => $projectConfiguration) {
        if ($projectConfiguration['url'] === $data['repository']['url']) {
            $name = $projectName;
            $project = $projectConfiguration;
            break;
        }
    }
    if ($name === null) {
        echo sprintf('Skipping request for URL %s (not configured)', $data['repository']['url']) . PHP_EOL;
        exit(0);
    }

    $ref = $data['ref'];
    if (isset($project['allowedRefsPattern']) && preg_match($project['allowedRefsPattern'], $ref) !== 1) {
        echo sprintf('Skipping request (blacklisted reference detected: %s)', $ref) . PHP_EOL;
        exit(0);
    }
    return [$config, $name, $project, $ref];
}

function prepareCheckout(array $config, string $name): string
{
    $projectWorkingDirectory = $config['working-directory'] . '/' . $name;

    if (!file_exists($projectWorkingDirectory)) {
        echo sprintf('Creating working directory (%s)', $projectWorkingDirectory) . PHP_EOL;
        if (!mkdir($projectWorkingDirectory, 0750, true) && !is_dir($projectWorkingDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $projectWorkingDirectory));
        }
    }

    $subtreeCachePath = $projectWorkingDirectory . '/.subsplit/.git/subtree-cache';
    if (file_exists($subtreeCachePath)) {
        echo sprintf('Removing subtree-cache (%s)', $subtreeCachePath);
        passthru(sprintf('rm -rf %s', escapeshellarg($subtreeCachePath)));
    }

    return $projectWorkingDirectory;
}

function buildPublishCommand(string $gitSubsplitBinary, array $project, string $ref): array
{
    $publishCommand = [
        sprintf('%s publish %s',
            $gitSubsplitBinary,
            escapeshellarg(implode(' ', $project['splits']))
        )
    ];

    if (preg_match('/refs\/tags\/(.+)$/', $ref, $matches)) {
        $publishCommand[] = escapeshellarg('--no-heads');
        $publishCommand[] = escapeshellarg(sprintf('--tags=%s', $matches[1]));
        $branch = preg_replace('/\.[0-9]+(?:-(?:alpha|beta|rc)[0-9]+)?$/i', '', $matches[1]);
    } elseif (preg_match('/refs\/heads\/(.+)$/', $ref, $matches)) {
        $publishCommand[] = escapeshellarg('--no-tags');
        $publishCommand[] = escapeshellarg(sprintf('--heads=%s', $matches[1]));
        $branch = $matches[1];
    } else {
        echo sprintf('Skipping request (unexpected reference detected: %s)', $ref) . PHP_EOL;
        exit(0);
    }

    echo sprintf('Detected branch %s', $branch) . PHP_EOL;

    return [$publishCommand, $branch];
}

[$config, $name, $project, $ref] = processPayload($configurationPathAndFilename, $argv);
[$publishCommand, $branch] = buildPublishCommand($gitSubsplitBinary, $project, $ref);
$projectWorkingDirectory = prepareCheckout($config, $name);

$repositoryUrl = $project['repository-url'] ?? $project['url'];
$commands = [
    sprintf('( %s init %s || true )', $gitSubsplitBinary, escapeshellarg($repositoryUrl)),
    sprintf('%s update', $gitSubsplitBinary),
    sprintf('git -C .subsplit checkout %s', escapeshellarg('origin/' . $branch)),
    implode(' ', $publishCommand)
];

chdir($projectWorkingDirectory);

foreach ($commands as $command) {
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        echo sprintf('Command %s had a problem, exit code %s', $command, $exitCode) . PHP_EOL;
        exit($exitCode);
    }
}

// special case for Neos.Fusion.Afx
// see https://github.com/neos/slicer/issues/11

if ($name === 'Neos') {
    $project['splits'] = ['Neos.Fusion.Afx:git@github.com:neos/fusion-afx.git'];
    [$publishCommand, $branch] = buildPublishCommand($gitSubsplitBinaryForNeosFusionAfx, $project, $ref);
    $command = implode(' ', $publishCommand);

    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        echo sprintf('Command %s had a problem, exit code %s', $command, $exitCode) . PHP_EOL;
        exit($exitCode);
    }
}
