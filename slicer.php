<?php
declare(strict_types = 1);

if (count($argv) !== 2) {
    echo 'Usage: slicer.php "payload as JSON"' . PHP_EOL;
    exit(1);
}
$slicer = new Slicer(__DIR__ . '/config.json');
$slicer->run($argv[1]);

class Slicer
{
    /**
     * @var array
     */
    protected $configuration = [];

    /**
     * @var  string
     */
    protected $projectWorkingDirectory;

    /**
     * Slicer constructor.
     *
     * @param string $configurationPathAndFilename
     */
    public function __construct(string $configurationPathAndFilename)
    {
        if (!file_exists($configurationPathAndFilename)) {
            echo 'Skipping request (config.json does not exist)' . PHP_EOL;
            exit(1);
        } else {
            $this->configuration = json_decode(file_get_contents($configurationPathAndFilename), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo 'Error parsing configuration: ' . json_last_error_msg() . PHP_EOL;
                exit(1);
            }
        }
    }

    /**
     * @param string $payload
     * @return void
     */
    public function run(string $payload)
    {
        list($projectConfiguration, $ref) = $this->processPayload($payload);

        $this->updateRepository($projectConfiguration);
        $this->split($projectConfiguration, $ref);
    }

    /**
     * @param string $payload
     * @return array
     */
    protected function processPayload(string $payload): array
    {
        $payload = $this->parsePayload($payload);

        $projectName = null;
        foreach ($this->configuration['projects'] as $projectName => $projectConfiguration) {
            if ($projectConfiguration['url'] === $payload['repository']['url']) {
                break;
            }
        }
        if ($projectName === null) {
            echo sprintf('Skipping request for URL %s (not configured)', $payload['repository']['url']) . PHP_EOL;
            exit(0);
        }

        $ref = $payload['ref'];
        if (isset($projectConfiguration['allowedRefsPattern']) && preg_match($projectConfiguration['allowedRefsPattern'], $ref) !== 1) {
            echo sprintf('Skipping request (blacklisted reference detected: %s)', $ref) . PHP_EOL;
            exit(0);
        }

        if (preg_match('/refs\/(heads|tags)\/(.+)$/', $ref, $matches) !== 1) {
            echo sprintf('Skipping request (unexpected reference detected: %s)', $ref) . PHP_EOL;
            exit(0);
        }

        $this->projectWorkingDirectory = __DIR__ . '/' . $this->configuration['working-directory'] . '/' . $projectName;
        if (!file_exists($this->projectWorkingDirectory)) {
            echo sprintf('Creating working directory (%s)', $this->projectWorkingDirectory) . PHP_EOL;
            mkdir($this->projectWorkingDirectory, 0750, true);
        }

        return [$projectConfiguration, $ref];
    }

    /**
     * @param string $payload
     * @return array
     */
    protected function parsePayload(string $payload): array
    {
        $payload = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo sprintf('Skipping request (could not decode payload: %s)', json_last_error_msg()) . PHP_EOL;
            exit(0);
        }

        if (!is_array($payload)) {
            echo 'Skipping request (unexpected payload)' . PHP_EOL;
            exit(0);
        }

        foreach (['repository', 'ref'] as $expectedKey) {
            if (array_key_exists($expectedKey, $payload) === false) {
                echo sprintf('Skipping request (key %s missing in payload)', $expectedKey) . PHP_EOL;
                exit(0);
            }
        }

        return $payload;
    }

    /**
     * @param array $project
     * @return void
     */
    protected function updateRepository(array $project)
    {
        $repositoryUrl = isset($project['repository-url']) ? $project['repository-url'] : $project['url'];

        if (is_dir($this->projectWorkingDirectory . '/refs') === false) {
            echo sprintf('Cloning %s', $repositoryUrl) . PHP_EOL;
            $gitCommand = 'git clone --bare %s .';
        } else {
            echo sprintf('Fetching %s', $repositoryUrl) . PHP_EOL;
            $gitCommand = 'git fetch --tags %s';
        }

        chdir($this->projectWorkingDirectory);
        $this->execute(sprintf($gitCommand, escapeshellarg($repositoryUrl)));
    }

    /**
     * @param array $project
     * @param string $ref
     * @return void
     */
    protected function split(array $project, string $ref)
    {
        chdir($this->projectWorkingDirectory);

        foreach ($project['splits'] as $prefix => $remote) {
            list($exitCode, ) = $this->execute(sprintf('git show %s:%s > /dev/null 2>&1', escapeshellarg($ref), escapeshellarg($prefix)), false);
            if ($exitCode !== 0) {
                echo sprintf('Skipping prefix %s, not present in %s', $prefix, $ref) . PHP_EOL;
                continue;
            }

            echo sprintf('Splitting %s of %s', $ref, $prefix) . PHP_EOL;
            list(, $result) = $this->execute(sprintf(
                'splitsh-lite --git %s --prefix %s --origin %s',
                escapeshellarg('<2.8.0'),
                escapeshellarg($prefix),
                escapeshellarg($ref)
            ));

            $commitHash = trim(current($result));
            if (preg_match('/^[0-9a-f]{40}$/', $commitHash) === 1) {
                echo sprintf('Pushing %s to %s as %s', $commitHash, $remote, $ref) . PHP_EOL;
                $this->execute(sprintf(
                    'git push %s %s:%s',
                    escapeshellarg($remote),
                    escapeshellarg($commitHash),
                    escapeshellarg($ref)
                ));
            }
        }
    }

    /**
     * @param string $command
     * @param bool $exitOnError
     * @return array
     */
    protected function execute(string $command, bool $exitOnError = true): array
    {
        $output = [];
        $exitCode = null;
        exec($command, $output, $exitCode);

        if ($exitOnError === true && $exitCode !== 0) {
            echo sprintf('Command "%s" had a problem, exit code %s', $command, $exitCode) . PHP_EOL;
            exit($exitCode);
        }

        return [$exitCode, $output];
    }
}
