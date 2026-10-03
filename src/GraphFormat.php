<?php

declare(strict_types=1);

namespace Quazardous\GramPHP;

/**
 * THE GRAPH AS DATA — the canonical form of a `Graph`, and its strict reader.
 *
 * A DEFINITION IS DATA: written as plain data, a graph can be kept next to a
 * migration, compared between two versions, drawn, or read by another tool.
 * The format is grampy's (`grampy/1`), so a graph moves between the Python
 * and the PHP implementations as it is.
 *
 * ONE CANONICAL FORM: `write` gives what differs from a default and nothing
 * else, nodes in declaration order — two equal graphs give the same array,
 * and a diff between two versions shows only what changed. `read` is strict:
 * an unknown key or a wrong type is refused with the path to it, because a
 * misspelled option silently ignored is a workflow that does not do what it
 * says. A document in a format this version does not know is refused rather
 * than half read.
 *
 * `group` is written when a node has one; grampy does not read it yet.
 */
final class GraphFormat
{
    /** The format this version reads and writes. */
    public const DSL = 'grampy/1';

    private const NODE_KEYS = ['parents', 'on', 'need', 'loop', 'retry', 'lease', 'wait', 'timeout', 'grace', 'rate', 'concurrency', 'per', 'lane', 'group', 'working', 'state', 'optional', 'once', 'choice'];

    private const LANE_KEYS = ['merge', 'position', 'cooldown', 'delay', 'max_wait', 'while_running', 'max_size'];

    /** @return array<string, mixed> */
    public static function write(Graph $graph): array
    {
        $nodes = [];
        foreach ($graph->dag as $n) {
            $spec = [];
            if ([] !== $n->parents) {
                $spec['parents'] = $n->parents;
            }
            foreach (['working' => $n->working, 'state' => $n->state] as $label => $value) {
                if (null !== $value) {
                    $spec[$label] = $value;
                }
            }
            if ([] !== $n->on) {
                $spec['on'] = $n->on;
            }
            if (null !== $n->need) {
                $spec['need'] = $n->need;
            }
            if (null !== $n->loop) {
                $spec['loop'] = ['to' => $n->loop->to, 'max' => $n->loop->max, 'on' => $n->loop->on];
            }
            foreach (['lease' => $n->lease, 'wait' => $n->wait, 'timeout' => $n->timeout, 'grace' => $n->grace] as $key => $value) {
                if (null !== $value) {
                    $spec[$key] = $value;
                }
            }
            if (null !== $n->retry) {
                $spec['retry'] = self::writeRetry($n->retry);
            }
            if ([] !== $n->rate) {
                $spec['rate'] = array_map(self::writeRate(...), $n->rate);
            }
            if (null !== $n->concurrency) {
                $spec['concurrency'] = $n->concurrency;
            }
            if (Per::All !== $n->per) {
                $spec['per'] = $n->per->value;
            }
            if (null !== $n->lane) {
                $spec['lane'] = self::writeLane($n->lane);
            }
            if (null !== $n->group) {
                $spec['group'] = array_filter(
                    ['size' => $n->group->size, 'max_wait' => $n->group->maxWait, 'per_key' => $n->group->perKey ? null : false],
                    static fn(mixed $v): bool => null !== $v,
                );
            }
            foreach (['optional' => $n->optional, 'once' => $n->once, 'choice' => $n->choice] as $flag => $on) {
                if ($on) {
                    $spec[$flag] = true;
                }
            }
            $nodes[$n->name] = $spec;
        }
        $d = $graph->document;
        $out = ['document' => ['dsl' => self::DSL, 'namespace' => $d->namespace, 'name' => $d->name, 'version' => $d->version], 'nodes' => $nodes];
        if ([] !== $graph->policies) {
            $policies = [];
            foreach ($graph->policies as $policy => $overrides) {
                foreach ($overrides as $name => $settings) {
                    foreach ($settings as $key => $value) {
                        $policies[$policy][$name][$key] = match (true) {
                            $value instanceof Lane => self::writeLane($value),
                            $value instanceof Retry => self::writeRetry($value),
                            'rate' === $key && \is_array($value) => array_map(static fn(mixed $b): array => self::writeRate($b instanceof Rate ? $b : throw new \LogicException('a rate band')), $value),
                            default => $value,
                        };
                    }
                }
            }
            $out['policies'] = $policies;
        }

        return $out;
    }

    /**
     * The canonical form as JSON, laid out as grampy writes it — two spaces,
     * `{}` for an empty object — so that the same graph is the same file in
     * either implementation.
     */
    public static function toJson(Graph $graph): string
    {
        $text = json_encode(self::objects(self::write($graph)), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^( +)/m', static fn(array $m): string => substr($m[1], 0, intdiv(\strlen($m[1]), 2)), $text) . "\n";
    }

    public static function fromJson(string $text): Graph
    {
        try {
            $data = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GraphFormatError('$: not JSON — ' . $e->getMessage(), 0, $e);
        }

        return self::read($data);
    }

    /** A graph from its canonical form. A document that lies is refused with the path to the lie. */
    public static function read(mixed $data): Graph
    {
        $top = self::mapping($data, '$', ['document', 'nodes'], ['document', 'nodes', 'policies']);
        $head = self::mapping($top['document'], '$.document', ['name'], ['dsl', 'namespace', 'name', 'version']);
        foreach ($head as $key => $value) {
            self::string($value, "\$.document.{$key}");
        }
        $dsl = $head['dsl'] ?? self::DSL;
        if (self::DSL !== $dsl) {
            throw new GraphFormatError(\sprintf("\$.document.dsl: '%s' is not a format this version reads ('%s')", self::text($dsl), self::DSL));
        }
        $document = new Document(self::text($head['name']), self::text($head['version'] ?? '0'), self::text($head['namespace'] ?? 'default'));

        if (!\is_array($top['nodes']) || array_is_list($top['nodes']) && [] !== $top['nodes']) {
            throw new GraphFormatError('$.nodes: expected an object of nodes by name');
        }
        $nodes = [];
        foreach ($top['nodes'] as $name => $raw) {
            $nodes[] = self::readNode((string) $name, $raw);
        }
        $policies = [];
        $raw = $top['policies'] ?? [];
        if (!\is_array($raw) || array_is_list($raw) && [] !== $raw) {
            throw new GraphFormatError('$.policies: expected an object of policies by name');
        }
        foreach ($raw as $policy => $overrides) {
            if (!\is_array($overrides) || array_is_list($overrides) && [] !== $overrides) {
                throw new GraphFormatError("\$.policies.{$policy}: expected an object of nodes");
            }
            foreach ($overrides as $name => $settings) {
                $path = "\$.policies.{$policy}.{$name}";
                foreach (self::mapping($settings, $path, [], Graph::OVERRIDABLE) as $key => $value) {
                    $policies[(string) $policy][(string) $name][$key] = match ($key) {
                        'retry' => self::readRetry($value, "{$path}.retry"),
                        'rate' => self::readRates($value, "{$path}.rate"),
                        'concurrency' => self::count($value, "{$path}.concurrency"),
                        'lane' => self::readLane($value, "{$path}.lane"),
                        default => self::duration($value, "{$path}.{$key}"),
                    };
                }
            }
        }

        try {
            return new Graph($document, new Dag(...$nodes), $policies);
        } catch (\InvalidArgumentException $e) {
            throw new GraphFormatError('$: ' . $e->getMessage(), 0, $e);
        }
    }

    // -- inside ----------------------------------------------------------

    private static function readNode(string $name, mixed $raw): Node
    {
        $path = "\$.nodes.{$name}";
        $spec = self::mapping($raw, $path, [], self::NODE_KEYS);
        $parents = $spec['parents'] ?? [];
        if (!\is_array($parents) || !array_is_list($parents)) {
            throw new GraphFormatError("{$path}.parents: expected a list of node names");
        }
        foreach ($parents as $i => $parent) {
            self::string($parent, "{$path}.parents[{$i}]");
        }
        foreach (['working', 'state', 'wait'] as $label) {
            if (isset($spec[$label])) {
                self::string($spec[$label], "{$path}.{$label}");
            }
        }
        foreach (['optional', 'once', 'choice'] as $flag) {
            if (\array_key_exists($flag, $spec) && !\is_bool($spec[$flag])) {
                throw new GraphFormatError("{$path}.{$flag}: expected true or false");
            }
        }
        $on = $spec['on'] ?? [];
        if (!\is_array($on) || array_is_list($on) && [] !== $on) {
            throw new GraphFormatError("{$path}.on: expected an object of statuses by parent");
        }
        foreach ($on as $parent => $statuses) {
            self::strings($statuses, "{$path}.on.{$parent}", 'a list of statuses');
        }
        $loop = null;
        if (isset($spec['loop'])) {
            $raw = self::mapping($spec['loop'], "{$path}.loop", ['to', 'max'], ['to', 'max', 'on']);
            self::string($raw['to'], "{$path}.loop.to");
            $loop = new Loop(self::text($raw['to']), self::count($raw['max'], "{$path}.loop.max") ?? 0, self::strings($raw['on'] ?? [Status::Failed->value], "{$path}.loop.on", 'a list of statuses'));
        }
        $per = $spec['per'] ?? Per::All->value;
        self::string($per, "{$path}.per");
        $group = null;
        if (isset($spec['group'])) {
            $raw = self::mapping($spec['group'], "{$path}.group", ['size'], ['size', 'max_wait', 'per_key']);
            if (isset($raw['per_key']) && !\is_bool($raw['per_key'])) {
                throw new GraphFormatError("{$path}.group.per_key: expected true or false");
            }
            $group = new Group(self::count($raw['size'], "{$path}.group.size") ?? 0, self::duration($raw['max_wait'] ?? null, "{$path}.group.max_wait"), $raw['per_key'] ?? true);
        }

        try {
            return new Node(
                $name,
                parents: self::strings($parents, "{$path}.parents", 'a list of node names'),
                working: isset($spec['working']) ? self::text($spec['working']) : null,
                state: isset($spec['state']) ? self::text($spec['state']) : null,
                optional: true === ($spec['optional'] ?? false),
                once: true === ($spec['once'] ?? false),
                on: self::edges($on, $path),
                need: self::count($spec['need'] ?? null, "{$path}.need"),
                choice: true === ($spec['choice'] ?? false),
                loop: $loop,
                retry: isset($spec['retry']) ? self::readRetry($spec['retry'], "{$path}.retry") : null,
                lease: self::duration($spec['lease'] ?? null, "{$path}.lease"),
                wait: isset($spec['wait']) ? self::text($spec['wait']) : null,
                timeout: self::duration($spec['timeout'] ?? null, "{$path}.timeout"),
                grace: self::duration($spec['grace'] ?? null, "{$path}.grace"),
                lane: isset($spec['lane']) ? self::readLane($spec['lane'], "{$path}.lane") : null,
                rate: isset($spec['rate']) ? self::readRates($spec['rate'], "{$path}.rate") : [],
                concurrency: self::count($spec['concurrency'] ?? null, "{$path}.concurrency"),
                per: Per::tryFrom(self::text($per)) ?? throw new GraphFormatError(\sprintf("%s.per: '%s' — expected all or policy", $path, self::text($per))),
                group: $group,
            );
        } catch (\ValueError|\InvalidArgumentException $e) {
            throw new GraphFormatError("{$path}: " . $e->getMessage(), 0, $e);
        }
    }

    /** An empty array is an empty object in the canonical form: no list is ever written empty. */
    private static function objects(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        return [] === $value ? new \stdClass() : array_map(self::objects(...), $value);
    }

    /**
     * @param array<mixed> $on
     *
     * @return array<string, list<string>>
     */
    private static function edges(array $on, string $path): array
    {
        $out = [];
        foreach ($on as $parent => $statuses) {
            $out[(string) $parent] = self::strings($statuses, "{$path}.on.{$parent}", 'a list of statuses');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function writeRetry(Retry $retry): array
    {
        $out = ['limit' => $retry->limit, 'delay' => $retry->delay, 'backoff' => $retry->backoff->value];
        if (null !== $retry->maxDelay) {
            $out['max_delay'] = $retry->maxDelay;
        }
        if (0.0 !== $retry->jitter) {
            $out['jitter'] = $retry->jitter;
        }

        return $out;
    }

    private static function readRetry(mixed $value, string $path): Retry
    {
        $raw = self::mapping($value, $path, ['limit'], ['limit', 'delay', 'backoff', 'max_delay', 'jitter']);
        try {
            $jitter = $raw['jitter'] ?? 0.0;

            return new Retry(
                self::count($raw['limit'], "{$path}.limit") ?? 0,
                self::duration($raw['delay'] ?? 0, "{$path}.delay") ?? 0,
                Backoff::from(self::text($raw['backoff'] ?? Backoff::Exponential->value)),
                self::duration($raw['max_delay'] ?? null, "{$path}.max_delay"),
                \is_int($jitter) || \is_float($jitter) ? (float) $jitter : throw new GraphFormatError("{$path}.jitter: expected a number"),
            );
        } catch (\ValueError|\InvalidArgumentException $e) {
            throw $e instanceof GraphFormatError ? $e : new GraphFormatError("{$path}: " . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private static function writeRate(Rate $band): array
    {
        return array_filter(['limit' => $band->limit, 'period' => $band->period, 'burst' => $band->burst], static fn(mixed $v): bool => null !== $v);
    }

    /** @return list<Rate> */
    private static function readRates(mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new GraphFormatError("{$path}: expected a list of bands");
        }
        $bands = [];
        foreach ($value as $i => $raw) {
            $spec = self::mapping($raw, "{$path}[{$i}]", ['limit', 'period'], ['limit', 'period', 'burst']);
            try {
                $bands[] = new Rate(self::count($spec['limit'], "{$path}[{$i}].limit") ?? 0, self::duration($spec['period'], "{$path}[{$i}].period") ?? 0, self::count($spec['burst'] ?? null, "{$path}[{$i}].burst"));
            } catch (\InvalidArgumentException $e) {
                throw $e instanceof GraphFormatError ? $e : new GraphFormatError("{$path}[{$i}]: " . $e->getMessage(), 0, $e);
            }
        }

        return $bands;
    }

    /** @return array<string, mixed> only what differs from `new Lane()` */
    private static function writeLane(Lane $lane): array
    {
        $default = new Lane();
        $out = [];
        foreach ([
            'merge' => [$lane->merge, $default->merge],
            'position' => [$lane->position->value, $default->position->value],
            'cooldown' => [$lane->cooldown, null],
            'delay' => [$lane->delay, null],
            'max_wait' => [$lane->maxWait, null],
            'while_running' => [$lane->whileRunning->value, $default->whileRunning->value],
            'max_size' => [$lane->maxSize, $default->maxSize],
        ] as $key => [$value, $unset]) {
            if ($value !== $unset) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function readLane(mixed $value, string $path): Lane
    {
        $raw = self::mapping($value, $path, [], self::LANE_KEYS);
        foreach (['merge', 'position', 'while_running'] as $key) {
            if (isset($raw[$key])) {
                self::string($raw[$key], "{$path}.{$key}");
            }
        }
        try {
            return new Lane(
                self::text($raw['merge'] ?? Merge::Last->value),
                Position::from(self::text($raw['position'] ?? Position::First->value)),
                self::duration($raw['cooldown'] ?? null, "{$path}.cooldown"),
                self::duration($raw['delay'] ?? null, "{$path}.delay"),
                self::duration($raw['max_wait'] ?? null, "{$path}.max_wait"),
                WhileRunning::from(self::text($raw['while_running'] ?? WhileRunning::Queue->value)),
                self::count($raw['max_size'] ?? 100, "{$path}.max_size") ?? 100,
            );
        } catch (\ValueError $e) {
            throw new GraphFormatError("{$path}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private static function mapping(mixed $value, string $path, array $required, array $allowed): array
    {
        if (!\is_array($value) || array_is_list($value) && [] !== $value) {
            throw new GraphFormatError("{$path}: expected an object");
        }
        $keys = array_map('strval', array_keys($value));
        $unknown = array_values(array_diff($keys, $allowed));
        if ([] !== $unknown) {
            sort($unknown);
            throw new GraphFormatError(\sprintf('%s: unknown key(s) [%s] — expected among [%s]', $path, implode(', ', $unknown), implode(', ', $allowed)));
        }
        $missing = array_values(array_diff($required, $keys));
        if ([] !== $missing) {
            throw new GraphFormatError(\sprintf('%s: missing key(s) [%s]', $path, implode(', ', $missing)));
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    private static function string(mixed $value, string $path): void
    {
        if (!\is_string($value) || '' === $value) {
            throw new GraphFormatError("{$path}: expected a non-empty string");
        }
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $path, string $what): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new GraphFormatError("{$path}: expected {$what}");
        }
        foreach ($value as $i => $item) {
            self::string($item, "{$path}[{$i}]");
        }

        return array_map(self::text(...), $value);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new GraphFormatError('expected a string');
    }

    private static function count(mixed $value, string $path): ?int
    {
        if (null !== $value && !\is_int($value)) {
            throw new GraphFormatError("{$path}: expected an integer");
        }

        return $value;
    }

    private static function duration(mixed $value, string $path): int|float|string|null
    {
        if (null !== $value && !\is_int($value) && !\is_float($value) && !\is_string($value)) {
            throw new GraphFormatError("{$path}: expected a duration (30s, 10m, 2h)");
        }

        return $value;
    }
}
