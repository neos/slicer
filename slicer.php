<?php
$gitSubsplitBinary = '"' . __DIR__ . '/git-subsplit/git-subsplit.sh"';
$configurationPathAndFilename = __DIR__ . '/config.json';

if (!file_exists($configurationPathAndFilename)) {
    echo 'Skipping request (config.json does not exist)' . PHP_EOL;
    exit(1);
} else {
    $config = json_decode(file_get_contents($configurationPathAndFilename), true);
}

$data = json_decode($argv[1], true);

if (!is_array($data)) {
    echo sprintf('Skipping request (could not decode payload: %s)', $argv[1]) . PHP_EOL;
    exit(0);
}

$name = null;
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

$repositoryUrl = isset($project['repository-url']) ? $project['repository-url'] : $project['url'];

$projectWorkingDirectory = $config['working-directory'] . '/' . $name;
if (!file_exists($projectWorkingDirectory)) {
    echo sprintf('Creating working directory (%s)', $projectWorkingDirectory) . PHP_EOL;
    mkdir($projectWorkingDirectory, 0750, true);
}

$subtreeCachePath = $projectWorkingDirectory . '/.subsplit/.git/subtree-cache';
if (file_exists($subtreeCachePath)) {
    echo sprintf('Removing subtree-cache (%s)', $subtreeCachePath);
    passthru(sprintf('rm -rf %s', escapeshellarg($subtreeCachePath)));
}

echo sprintf('Detected branch %s', $branch) . PHP_EOL;

$command = implode(' && ', [
    sprintf('cd %s', escapeshellarg($projectWorkingDirectory)),
    sprintf('( %s init %s || true )', $gitSubsplitBinary, escapeshellarg($repositoryUrl)),
    sprintf('%s update', $gitSubsplitBinary),
    'cd .subsplit',
    sprintf('git checkout %s', escapeshellarg('origin/' . $branch)),
    'cd ..',
    implode(' ', $publishCommand)
]);

passthru($command, $exitCode);

if (0 !== $exitCode) {
    echo sprintf('Command %s had a problem, exit code %s', $command, $exitCode) . PHP_EOL;
    exit($exitCode);
}
