<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * No-op adapter that skips admin user creation entirely.
 *
 * This is the right choice when the application manages its own first-run
 * setup, or when the operator prefers to create admin accounts manually.
 */
class NoneAdapter implements AuthAdapterInterface
{
    public function canHandle(): bool
    {
        return true; // always applicable
    }

    public function getFields(): array
    {
        return []; // no fields to collect
    }

    public function createAdmin(array $data): Result
    {
        return Result::ok(); // deliberately do nothing
    }
}
