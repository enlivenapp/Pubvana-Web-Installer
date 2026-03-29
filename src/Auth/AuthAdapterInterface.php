<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

interface AuthAdapterInterface
{
    /**
     * Can this adapter work in the current environment?
     */
    public function canHandle(): bool;

    /**
     * What fields to collect from the user in the wizard.
     * Returns array of field definitions: ['name' => 'email', 'type' => 'email', 'label' => 'Email', ...]
     */
    public function getFields(): array;

    /**
     * Create the admin user with the provided form data.
     */
    public function createAdmin(array $data): Result;
}
