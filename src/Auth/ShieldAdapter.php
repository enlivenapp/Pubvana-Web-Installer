<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * Auth adapter for CodeIgniter Shield.
 *
 * @see https://shield.codeigniter.com
 */
class ShieldAdapter implements AuthAdapterInterface
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $group = 'superadmin',
    ) {}

    // -------------------------------------------------------------------------

    public function canHandle(): bool
    {
        try {
            $this->bootstrapCI4();
        } catch (\Throwable $e) {
            return false;
        }

        return class_exists('CodeIgniter\Shield\Models\UserModel');
    }

    public function getFields(): array
    {
        return [
            [
                'name'     => 'username',
                'type'     => 'text',
                'label'    => 'Username',
                'required' => true,
            ],
            [
                'name'     => 'email',
                'type'     => 'email',
                'label'    => 'Email',
                'required' => true,
            ],
            [
                'name'     => 'password',
                'type'     => 'password',
                'label'    => 'Password',
                'required' => true,
            ],
        ];
    }

    public function createAdmin(array $data): Result
    {
        try {
            $this->bootstrapCI4();
        } catch (\Throwable $e) {
            return Result::fail('CI4 bootstrap failed: ' . $e->getMessage());
        }

        try {
            /** @var \CodeIgniter\Shield\Models\UserModel $userModel */
            $userModel = new \CodeIgniter\Shield\Models\UserModel();

            /** @var \CodeIgniter\Shield\Entities\User $user */
            $user = new \CodeIgniter\Shield\Entities\User([
                'username' => $data['username'] ?? null,
            ]);

            // Shield stores credentials (email + password) separately via the
            // identity system — populate them before saving.
            $user->fill([
                'username' => $data['username'] ?? null,
            ]);

            // Set email / password via the magic email identity helper that
            // Shield's User entity provides.
            $user->email    = $data['email']    ?? '';
            $user->password = $data['password'] ?? '';

            if (! $userModel->save($user)) {
                $errors = implode(' ', $userModel->errors());
                return Result::fail('Failed to create Shield user: ' . $errors);
            }

            // Reload from DB so we have the persisted ID.
            $saved = $userModel->findById($userModel->getInsertID());

            if ($saved === null) {
                return Result::fail('Shield user was saved but could not be reloaded.');
            }

            $saved->addGroup($this->group);

            return Result::ok(['user_id' => $saved->id]);
        } catch (\Throwable $e) {
            return Result::fail('Shield createAdmin error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function bootstrapCI4(): void
    {
        if (defined('ROOTPATH')) {
            return; // already bootstrapped
        }

        define('ROOTPATH',    $this->appRoot . '/');
        define('APPPATH',     $this->appRoot . '/app/');
        define('WRITABLEPATH', $this->appRoot . '/writable/');
        define('SYSTEMPATH',  $this->appRoot . '/vendor/codeigniter4/framework/system/');

        require ROOTPATH . 'vendor/autoload.php';
        require SYSTEMPATH . 'util_bootstrap.php';
    }
}
