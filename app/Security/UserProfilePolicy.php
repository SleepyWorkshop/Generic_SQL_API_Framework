<?php

final class UserProfilePolicy
{
    public static function name($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        $name = trim($value);
        if (strlen($name) > 120) throw new InvalidArgumentException('Name must not exceed 120 characters.');
        return $name;
    }

    public static function mobile($value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Mobile number is required.');
        }
        $mobile = trim($value);
        if (strlen($mobile) > 24 || preg_match('/^\+?[0-9][0-9 ()-]{6,22}[0-9]$/', $mobile) !== 1) {
            throw new InvalidArgumentException('Mobile number is invalid.');
        }
        return $mobile;
    }

    public static function email($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new InvalidArgumentException('Email must be a string.');
        $email = trim($value);
        if ($email === '') return null;
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email is invalid.');
        }
        return $email;
    }
}
