<?php

namespace Xofttion\Kernel\Structs;

use DomainException;
use Xofttion\Kernel\Contracts\IJson;

class Json implements IJson
{
    private array $data = [];

    private bool $readonly;

    public function __construct(?array $data = null, bool $readonly = true)
    {
        $this->readonly = $readonly;

        if (is_defined($data)) {
            $this->map($data);
        }
    }

    protected function map(array $data): bool
    {
        if (!is_array_json($data)) {
            return false;
        }

        $keys = array_keys($data);

        foreach ($keys as $key) {
            $this[$key] = static::getValue($data[$key]);
        }

        return true;
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize()
    {
        $keys = array_keys($this->data);

        $json = [];

        foreach ($keys as $key) {
            $json[$key] = static::jsonToValue($this->data[$key]);
        }

        return $json;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset)
    {
        if ($this->offsetExists($offset)) {
            return $this->data[$offset];
        }

        return null;
    }

    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        if ($this->offsetExists($offset)) {
            unset($this->data[$offset]);
        }
    }

    public function &__get($key)
    {
        return $this->data[$key];
    }

    public function __set($key, $value)
    {
        if (!$this->readonly) {
            $this->data[$key] = $value;
        }
    }

    public function __isset($key)
    {
        return isset($this->data[$key]);
    }

    public function __unset($key)
    {
        unset($this->data[$key]);
    }

    public function __toString()
    {
        return json_encode($this->jsonSerialize());
    }

    public static function array(array $data): array
    {
        if (is_array_json($data)) {
            throw new DomainException('An array is expected to be received');
        }

        return static::getValueArray($data);
    }

    protected static function getValue($data)
    {
        if (is_array($data)) {
            if (!is_array_json($data)) {
                return static::getValueArray($data);
            }

            return new static($data);
        }

        return $data;
    }

    protected static function getValueArray($data)
    {
        $array = [];

        foreach ($data as $element) {
            $array[] = static::getValue($element);
        }

        return $array;
    }

    protected static function jsonToValue($value)
    {
        if ($value instanceof IJson) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return static::jsonToArray($value);
        }

        return $value;
    }

    protected static function jsonToArray(array $data): array
    {
        $array = [];

        foreach ($data as $element) {
            $array[] = static::jsonToValue($element);
        }

        return $array;
    }
}
