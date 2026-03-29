<?php

namespace CI4Installer;

/**
 * Consistent result object returned by all installer operations.
 */
class Result
{
    public function __construct(
        public readonly bool $success,
        public readonly string $errorMessage = '',
        public readonly mixed $content = null,
    ) {}

    public static function ok(mixed $content = null): self
    {
        return new self(true, '', $content);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
