<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';
require_once __DIR__ . '/../Security/PasswordPolicy.php';
require_once __DIR__ . '/../Security/UserProfilePolicy.php';

final class SetupRequestValidator
{
    public const MINIMUM_PASSWORD_LENGTH = PasswordPolicy::MINIMUM_LENGTH;
    public const MAXIMUM_PASSWORD_LENGTH = PasswordPolicy::MAXIMUM_LENGTH;
    public const MAXIMUM_USERNAME_LENGTH = UsernamePolicy::MAXIMUM_LENGTH;

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if ($action === 'setup.status') {
            $this->rejectUnknown($request, ['action']);
            return ['action' => $action];
        }
        if ($action !== 'setup.createAdmin') {
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST');
        }

        $this->rejectUnknown($request, ['action', 'name', 'username', 'mobile', 'email', 'password', 'passwordConfirmation']);
        $details = [];
        foreach (['name' => 'name', 'mobile' => 'mobile', 'email' => 'email'] as $field => $method) {
            try { $$field = UserProfilePolicy::$method($request[$field] ?? null); }
            catch (InvalidArgumentException $exception) { $details[] = ['path' => $field, 'message' => $exception->getMessage()]; }
        }
        $username = $request['username'] ?? null;
        $password = $request['password'] ?? null;
        $confirmation = $request['passwordConfirmation'] ?? null;

        try {
            $username = UsernamePolicy::normalize($username);
        } catch (InvalidArgumentException $exception) {
            $details[] = ['path' => 'username', 'message' => $exception->getMessage()];
        }

        try {
            $password = PasswordPolicy::validate($password);
        } catch (InvalidArgumentException $exception) {
            $details[] = ['path' => 'password', 'message' => $exception->getMessage()];
        }

        if (!is_string($confirmation)) {
            $details[] = ['path' => 'passwordConfirmation', 'message' => 'Password confirmation is required.'];
        } elseif (is_string($password) && !hash_equals($password, $confirmation)) {
            $details[] = ['path' => 'passwordConfirmation', 'message' => 'Password confirmation does not match.'];
        }

        if ($details !== []) {
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST', $details);
        }

        return [
            'action' => $action,
            'name' => $name,
            'username' => $username,
            'mobile' => $mobile,
            'email' => $email,
            'password' => $password,
        ];
    }

    private function rejectUnknown(array $request, array $allowed): void
    {
        $details = [];
        foreach (array_keys($request) as $key) {
            if (!in_array($key, $allowed, true)) {
                $details[] = ['path' => $key, 'message' => 'Unknown property.'];
            }
        }
        if ($details !== []) {
            throw new ApiRequestException('Invalid setup request.', 'INVALID_SETUP_REQUEST', $details);
        }
    }
}
