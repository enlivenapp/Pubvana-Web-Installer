<?php

namespace CI4Installer\Auth;

use CI4Installer\Result;

/**
 * Auth adapter for the IonAuth library (CI4 port).
 *
 * @see https://github.com/benedmunds/CodeIgniter-Ion-Auth/tree/4
 */
class IonAuthAdapter implements AuthAdapterInterface
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

        return class_exists('IonAuth\Libraries\IonAuth');
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
            $ionAuth = new \IonAuth\Libraries\IonAuth();

            $email    = $data['email']    ?? '';
            $password = $data['password'] ?? '';
            $username = $data['username'] ?? '';

            // IonAuth::register($username, $password, $email, $additionalData, $groupIds)
            $userId = $ionAuth->register($username, $password, $email);

            if ($userId === false) {
                $messages = $ionAuth->errors()->line(' ');
                return Result::fail('IonAuth register failed: ' . $messages);
            }

            // Retrieve the group ID by name and add the user to it.
            $groupModel = new \IonAuth\Models\IonAuthModel();
            $group      = $groupModel->getGroupByName($this->group);

            if ($group === false || $group === null) {
                return Result::fail(
                    sprintf('IonAuth group "%s" not found.', $this->group)
                );
            }

            if (! $ionAuth->addToGroup($group->id, $userId)) {
                $messages = $ionAuth->errors()->line(' ');
                return Result::fail('IonAuth addToGroup failed: ' . $messages);
            }

            return Result::ok(['user_id' => $userId]);
        } catch (\Throwable $e) {
            return Result::fail('IonAuth createAdmin error: ' . $e->getMessage());
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
