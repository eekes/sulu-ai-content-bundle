<?php

declare(strict_types=1);

namespace Eekes\Sulu\AiContentBundle\Tests\Double;

use PHPUnit\Framework\Assert;

/**
 * Reads decoded JSON by a dot path ("reply.questions.0.question"), failing the test when the
 * path is not there - so assertions on API responses stay one line and stay typed.
 */
final class Json
{
    /**
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($data);

        return $data;
    }

    public static function at(mixed $data, string $path): mixed
    {
        foreach ($path === '' ? [] : explode('.', $path) as $key) {
            Assert::assertIsArray($data, \sprintf('"%s" is not reachable.', $path));
            Assert::assertArrayHasKey($key, $data, \sprintf('"%s" is missing.', $path));
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $data, string $path = ''): array
    {
        $value = self::at($data, $path);
        Assert::assertIsArray($value, \sprintf('"%s" is not a list.', $path));

        return $value;
    }

    public static function string(mixed $data, string $path): string
    {
        $value = self::at($data, $path);
        Assert::assertIsString($value, \sprintf('"%s" is not a string.', $path));

        return $value;
    }
}
