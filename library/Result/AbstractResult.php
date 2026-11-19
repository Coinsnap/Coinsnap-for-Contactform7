<?php
declare(strict_types=1);
namespace Coinsnap\Result;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractResult implements \ArrayAccess {
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function offsetExists($offset): bool
    {
        $data = $this->getData();
        return array_key_exists($offset, $data);
    }

    // No "mixed" return type: it is PHP 8 only, and the plugin supports PHP 7.4.
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $data = $this->getData();
        return $data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \RuntimeException('You should not change the data in a result.');
    }

    public function offsetUnset($offset): void
    {
        throw new \RuntimeException('You should not change the data in a result.');
    }
}
