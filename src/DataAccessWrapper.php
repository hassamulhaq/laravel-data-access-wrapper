<?php

namespace Hassam\DataAccessWrapper;

class DataAccessWrapper
{
    public function __construct(protected array $config = [])
    {
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }
}
