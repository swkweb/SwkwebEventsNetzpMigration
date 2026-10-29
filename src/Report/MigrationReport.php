<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration\Report;

/**
 * Findings of a check, import or switch run. Personal data (attendee names, answers) only goes into the JSON file,
 * never into {@see getSummary()}, which is printed to the console.
 */
final class MigrationReport
{
    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var list<array{code: string, message: string, context: array<string, mixed>}>
     */
    private array $blocking = [];

    /**
     * @var list<array{code: string, message: string, context: array<string, mixed>}>
     */
    private array $warnings = [];

    /**
     * @var array<string, mixed>
     */
    private array $sections = [];

    public function count(string $key, int $increment = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $increment;
    }

    public function setCount(string $key, int $value): void
    {
        $this->counts[$key] = $value;
    }

    /**
     * @param array<string, mixed> $context written to the file only
     */
    public function block(string $code, string $message, array $context = []): void
    {
        $this->blocking[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /**
     * @param array<string, mixed> $context written to the file only
     */
    public function warn(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    public function setSection(string $name, mixed $data): void
    {
        $this->sections[$name] = $data;
    }

    public function hasBlockingIssues(): bool
    {
        return $this->blocking !== [];
    }

    /**
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    public function getBlocking(): array
    {
        return $this->blocking;
    }

    /**
     * @return list<array{code: string, message: string, context: array<string, mixed>}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    public function getSection(string $name): mixed
    {
        return $this->sections[$name] ?? null;
    }

    /**
     * Counts and the number of findings per code, without any context.
     *
     * @return array{counts: array<string, int>, blocking: array<string, int>, warnings: array<string, int>}
     */
    public function getSummary(): array
    {
        $group = static function (array $issues): array {
            $result = [];
            foreach ($issues as $issue) {
                $result[$issue['code']] = ($result[$issue['code']] ?? 0) + 1;
            }
            ksort($result);

            return $result;
        };

        ksort($this->counts);

        return ['counts' => $this->counts, 'blocking' => $group($this->blocking), 'warnings' => $group($this->warnings)];
    }

    public function write(string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $directory));
        }

        file_put_contents($path, json_encode([
            'createdAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ...$this->getSummary(),
            'blockingIssues' => $this->blocking,
            'warningIssues' => $this->warnings,
            ...$this->sections,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }
}
