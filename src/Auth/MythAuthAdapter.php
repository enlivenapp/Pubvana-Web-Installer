<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * Auth adapter for Myth:Auth library.
 *
 * @see https://github.com/lonnieezell/myth-auth
 */
class MythAuthAdapter implements AuthAdapterInterface
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $group = 'admin',
    ) {}

    // -------------------------------------------------------------------------

    public function canHandle(): bool
    {
        try {
            $this->bootstrapCI4();
        } catch (\Throwable $e) {
            return false;
        }

        return class_exists('Myth\Auth\Models\UserModel')
            && class_exists('Myth\Auth\Entities\User');
    }

    public function getFields(): array
    {
        return [
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
            /** @var \Myth\Auth\Models\UserModel $userModel */
            $userModel = new \Myth\Auth\Models\UserModel();

            /** @var \Myth\Auth\Entities\User $user */
            $user = new \Myth\Auth\Entities\User();
            $user->fill([
                'email'                => $data['email']    ?? '',
                'password'             => $data['password'] ?? '',
                'password_confirm'     => $data['password'] ?? '',
                'username'             => $data['username'] ?? ($data['email'] ?? ''),
                'active'               => 1,
            ]);

            // Myth:Auth hashes the password inside the entity / model.
            if (! $userModel->save($user)) {
                $errors = implode(' ', $userModel->errors());
                return Result::fail('Myth:Auth user save failed: ' . $errors);
            }

            $userId = $userModel->getInsertID();

            // Assign the user to the configured group.
            $authGroupModel = new \Myth\Auth\Models\GroupModel();

            $group = $authGroupModel->where('name', $this->group)->first();

            if ($group === null) {
                return Result::fail(
                    sprintf('Myth:Auth group "%s" not found.', $this->group)
                );
            }

            $authGroupModel->addUserToGroup($userId, $group->id);

            return Result::ok(['user_id' => $userId]);
        } catch (\Throwable $e) {
            return Result::fail('Myth:Auth createAdmin error: ' . $e->getMessage());
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
