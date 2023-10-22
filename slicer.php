<?php
declare(strict_types=1);

if (count($argv) !== 2) {
    echo 'Usage: slicer.php \'payload as JSON\'' . PHP_EOL;
    exit(1);
}
$slicer = new Slicer(__DIR__ . '/config.json');
$slicer->run($argv[1]);

class Slicer
{
    protected array $configuration = [];

    protected string $projectWorkingDirectory;

    public function __construct(string $configurationPathAndFilename)
    {
        if (!file_exists($configurationPathAndFilename)) {
            echo 'Skipping request (config.json does not exist)' . PHP_EOL;
            exit(1);
        }

        try {
            $this->configuration = json_decode(file_get_contents($configurationPathAndFilename), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            echo sprintf('Error parsing configuration: %s', $e->getMessage()) . PHP_EOL;
            exit(1);
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'Error parsing configuration: ' . json_last_error_msg() . PHP_EOL;
            exit(1);
        }
    }

    public function run(string $payload): void
    {
        [$projectConfiguration] = $this->processPayload($payload);

        $this->updateRepository($projectConfiguration);
        $this->split($projectConfiguration);
    }

    protected function processPayload(string $payload): array
    {
        $parsedPayload = $this->parsePayload($payload);

        $projectName = null;
        $projectConfiguration = [];
        foreach ($this->configuration['projects'] as $potentialProjectName => $projectConfiguration) {
            if ($projectConfiguration['url'] === $parsedPayload['repository']['url']) {
                $projectName = $potentialProjectName;
                break;
            }
        }
        if ($projectName === null || $projectConfiguration === []) {
            echo sprintf('Skipping request for URL %s (not configured)', $parsedPayload['repository']['url']) . PHP_EOL;
            exit(0);
        }

//        $ref = $parsedPayload['ref'];
//        if (isset($projectConfiguration['allowedRefsPattern']) && preg_match($projectConfiguration['allowedRefsPattern'], $ref) !== 1) {
//            echo sprintf('Skipping request (denied reference detected: %s)', $ref) . PHP_EOL;
//            exit(0);
//        }
//
//        if (preg_match('/refs\/(heads|tags)\/(.+)$/', $ref) !== 1) {
//            echo sprintf('Skipping request (unexpected reference detected: %s)', $ref) . PHP_EOL;
//            exit(0);
//        }

        $this->projectWorkingDirectory = __DIR__ . '/' . $this->configuration['working-directory'] . '/' . $projectName;
        if (!file_exists($this->projectWorkingDirectory)) {
            echo sprintf('Creating working directory (%s)', $this->projectWorkingDirectory) . PHP_EOL;
            if (!mkdir($concurrentDirectory = $this->projectWorkingDirectory, 0750, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        return [$projectConfiguration];
    }

    protected function parsePayload(string $payload): array
    {
        try {
            $parsedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            echo sprintf('Skipping request (could not decode payload: %s)', $e->getMessage()) . PHP_EOL;
            exit(1);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo sprintf('Skipping request (could not decode payload: %s)', json_last_error_msg()) . PHP_EOL;
            exit(1);
        }

        if (!is_array($parsedPayload)) {
            echo 'Skipping request (unexpected payload)' . PHP_EOL;
            exit(1);
        }

        foreach (['repository', 'ref'] as $expectedKey) {
            if (array_key_exists($expectedKey, $parsedPayload) === false) {
                echo sprintf('Skipping request (key %s missing in payload)', $expectedKey) . PHP_EOL;
                exit(1);
            }
        }

        return $parsedPayload;
    }

    protected function updateRepository(array $project): void
    {
        $repositoryUrl = $project['repository-url'] ?? $project['url'];

        if (is_dir($this->projectWorkingDirectory . '/refs') === false) {
            echo sprintf('Cloning %s', $repositoryUrl) . PHP_EOL;
            $gitCommand = 'git clone --bare %s .';
        } else {
            echo sprintf('Fetching %s', $repositoryUrl) . PHP_EOL;
            $gitCommand = 'git fetch -f --prune --tags %s';
        }

        chdir($this->projectWorkingDirectory);
        $this->execute(sprintf($gitCommand, escapeshellarg($repositoryUrl)));
    }

    protected function split(array $project): void
    {
        chdir($this->projectWorkingDirectory);
        chdir('../');

        foreach ($project['splits'] as $prefix => $config) {
            $splitDirectory = $this->projectWorkingDirectory . '/../split-' . $prefix;
            [$exitCode,] = $this->execute(sprintf('cp -r %s %s', escapeshellarg($this->projectWorkingDirectory), escapeshellarg($splitDirectory)), false);
            chdir($splitDirectory);
//            [$exitCode,] = $this->execute(sprintf('git show %s:%s > /dev/null 2>&1', escapeshellarg($ref), escapeshellarg($prefix)), false);
//            if ($exitCode !== 0) {
//                echo sprintf('Skipping prefix %s, not present in %s', $prefix, $ref) . PHP_EOL;
//                continue;
//            }

            echo sprintf('Splitting %s', $prefix) . PHP_EOL;
            $command = 'git-filter-repo';

            /**
             * These will be moved to top level of the split repo
             */
            foreach ($config['folders'] as $folderNameToSplit) {
                $command .= sprintf(' --subdirectory-filter %s', escapeshellarg($folderNameToSplit));
            }

            /**
             * These will only be scanned for commits but files will remain in there, workaround for
             * "File renaming caused colliding pathnames" errors happening in some renamed folders.
             */
            foreach ($config['additionalHistoryFolders'] ?? [] as $folderNameToConsiderCommits) {
                $command .= sprintf(' --path %s', escapeshellarg($folderNameToConsiderCommits));
            }

            [, $result] = $this->execute($command);

            $commitHash = trim(current($result));
//            if (preg_match('/^[0-9a-f]{40}$/', $commitHash) === 1) {
//                $this->push($target, $commitHash, $remote, $ref);
//            }

//            rmdir($splitDirectory);
        }
    }

    protected function push(string $target, string $commitHash, string $remote, string $ref): void
    {
        echo sprintf('Pushing %s (%s) to %s as %s', $target, $commitHash, $remote, $ref) . PHP_EOL;

        $target = 'refs/splits/' . $target;

        $this->execute(sprintf(
            'git update-ref %s %s',
            escapeshellarg($target),
            escapeshellarg($commitHash)
        ));

        $this->execute(sprintf(
            'git push %s %s:%s',
            escapeshellarg($remote),
            escapeshellarg($target),
            escapeshellarg($ref)
        ));
    }

    protected function execute(string $command, bool $exitOnError = true): array
    {
        $output = [];
        $exitCode = null;

        echo ' >> ' . $command . PHP_EOL;
        exec($command, $output, $exitCode);

        if ($exitOnError === true && $exitCode !== 0) {
            echo sprintf('Command "%s" had a problem, exit code %s', $command, $exitCode) . PHP_EOL;
            exit($exitCode);
        }

        return [$exitCode, $output];
    }
}
