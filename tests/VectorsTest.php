<?php

namespace Toggley\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Toggley\Engine\Bucketer;
use Toggley\Engine\Evaluator;
use Toggley\Engine\FixedRandom;
use Toggley\Engine\NativeRandom;

final class VectorsTest extends TestCase
{
    public static function evaluationCases(): array
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../spec/evaluation-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($vectors['suites'] as $suite) {
            foreach ($suite['cases'] as $case) {
                $cases[$suite['name'] . ': ' . $case['name']] = [$suite['document'], $case];
            }
        }
        return $cases;
    }

    #[DataProvider('evaluationCases')]
    public function testEvaluation(array $document, array $case): void
    {
        $random = array_key_exists('randomBucket', $case) ? new FixedRandom($case['randomBucket']) : new NativeRandom;
        $result = Evaluator::evaluate($document, $case['flag'], $case['context'], $case['default'], $random);
        if (isset($case['requestedType'])) {
            $result = Evaluator::typed($result, $case['requestedType'], $case['default']);
        }

        $this->assertSame(self::sortKeys($case['expect']), self::sortKeys($result));
    }

    public function testBucketing(): void
    {
        $vectors = json_decode(file_get_contents(__DIR__ . '/../spec/bucketing-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($vectors['buckets'] as $row) {
            $this->assertSame($row['bucket'], Bucketer::bucket($row['hash'], $row['salt'], $row['key'], new NativeRandom));
        }
    }

    public function testEngineMatchesThePlugin(): void
    {
        $plugin = __DIR__ . '/../../toggleyapp.com/plugins/albrightlabs/featureflag';
        if (!is_dir($plugin)) {
            $this->markTestSkipped('Toggley plugin checkout not found next to this repo.');
        }

        exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/sync-engine') . ' ' . escapeshellarg($plugin) . ' --check 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map([self::class, 'sortKeys'], $value);
    }
}
