<?php

namespace Toggley;

/**
 * Summarizer counts evaluations per flag, variation and flag version. Contexts are
 * never stored or sent. Summaries can be merged, so PHP-FPM requests can add their
 * counts to a shared cache entry.
 */
final class Summarizer
{
    public const MAX_COUNTERS = 1000;
    public const MAX_COUNTERS_PER_FLAG = 50;

    /** @var array<string, array<string, array>> flag key => counter id => counter */
    private array $features = [];
    private int $counters = 0;
    private ?int $startDate = null;
    public int $dropped = 0;

    public function __construct(private readonly \Closure $clock)
    {
    }

    public function isEmpty(): bool
    {
        return $this->features === [];
    }

    public function record(string $flagKey, array $result): void
    {
        $this->add($flagKey, $result['variation'], $result['flagVersion'], 1);
    }

    public function add(string $flagKey, ?string $variation, ?int $version, int $count): void
    {
        $this->startDate ??= $this->nowMs();
        $id = ($variation ?? '') . '|' . ($version ?? '');

        if (isset($this->features[$flagKey][$id])) {
            $this->features[$flagKey][$id]['count'] += $count;
            return;
        }

        if ($this->counters >= self::MAX_COUNTERS || count($this->features[$flagKey] ?? []) >= self::MAX_COUNTERS_PER_FLAG) {
            $this->dropped += $count;
            return;
        }

        $counter = ['variation' => $variation, 'count' => $count];
        if ($version !== null) {
            $counter['version'] = $version;
        }
        if ($variation === null) {
            $counter['unknown'] = true;
        }

        $this->features[$flagKey][$id] = $counter;
        $this->counters++;
    }

    /** toArray is the storable state, see merge(). */
    public function toArray(): array
    {
        return ['startDate' => $this->startDate, 'features' => $this->features];
    }

    public function merge(array $state): void
    {
        if (isset($state['startDate']) && is_int($state['startDate'])) {
            $this->startDate = $this->startDate === null ? $state['startDate'] : min($this->startDate, $state['startDate']);
        }

        foreach ($state['features'] ?? [] as $flagKey => $counters) {
            foreach ((array) $counters as $counter) {
                $this->add((string) $flagKey, $counter['variation'] ?? null, $counter['version'] ?? null, (int) ($counter['count'] ?? 0));
            }
        }
    }

    /** snapshot returns the POST /api/v2/sdk/events payload and resets, or null when empty. */
    public function snapshot(array $sdk): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $features = [];
        foreach ($this->features as $flagKey => $counters) {
            $features[$flagKey] = ['counters' => array_values($counters)];
        }

        $payload = [
            'kind' => 'summary',
            'startDate' => $this->startDate,
            'endDate' => max($this->nowMs(), $this->startDate),
            'sdk' => $sdk,
            'features' => $features,
        ];

        $this->features = [];
        $this->counters = 0;
        $this->startDate = null;

        return $payload;
    }

    private function nowMs(): int
    {
        return (int) floor(($this->clock)() * 1000);
    }
}
