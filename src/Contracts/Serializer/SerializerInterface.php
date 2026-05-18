<?php declare(strict_types=1);
namespace VLT\CacheManager\Contracts\Serializer;

interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function unserialize(string $data): mixed;
    public function name(): string;
    public function isAvailable(): bool;
}
