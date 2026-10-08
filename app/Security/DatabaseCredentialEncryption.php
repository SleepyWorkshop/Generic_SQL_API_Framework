<?php

require_once __DIR__ . '/DatabaseCredentialException.php';

class DatabaseCredentialEncryption
{
    public const ENVIRONMENT_VARIABLE = 'GENERIC_SQL_API_ENCRYPTION_KEY';
    public const VERSION = 1;
    /** Envelope version whose ciphertext is bound to its owner through AES-GCM AAD. */
    public const BOUND_VERSION = 2;
    public const ALGORITHM = 'AES-256-GCM';

    private const KEY_LENGTH = 32;
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(?string $encodedKey = null)
    {
        if ($encodedKey === null) {
            $environmentValue = getenv(self::ENVIRONMENT_VARIABLE);

            if ($environmentValue === false || trim($environmentValue) === '') {
                throw new DatabaseCredentialException(
                    'Database encryption key is not configured in '
                    . self::ENVIRONMENT_VARIABLE . '.'
                );
            }

            $encodedKey = $environmentValue;
        }

        $key = base64_decode(trim($encodedKey), true);

        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            throw new DatabaseCredentialException(
                'Database encryption key is invalid; '
                . self::ENVIRONMENT_VARIABLE
                . ' must contain a base64-encoded 32-byte key.'
            );
        }

        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new DatabaseCredentialException('The PHP OpenSSL extension is required for encrypted database configuration.');
        }

        $this->key = $key;
    }

    public function encryptPassword(string $password): array
    {
        return $this->encryptPayload($password);
    }

    public function decryptPassword(array $encryptedPassword): string
    {
        return $this->decryptPayload($encryptedPassword);
    }

    public function encryptConfiguration(array $configuration): array
    {
        try {
            $serialized = json_encode(
                $configuration,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database configuration encryption failed.');
        }

        return $this->encryptPayload($serialized);
    }

    public function decryptConfiguration(array $encryptedConfiguration): array
    {
        $serialized = $this->decryptPayload($encryptedConfiguration);

        try {
            $configuration = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database configuration decryption failed.');
        }

        if (!is_array($configuration) || array_is_list($configuration)) {
            throw new DatabaseCredentialException('Database configuration decryption failed.');
        }

        return $configuration;
    }

    /**
     * Seal a configuration object for one owner. The binding is authenticated
     * but not stored, so an envelope copied to another owner fails decryption.
     */
    public function encryptBound(array $value, string $binding): array
    {
        try {
            $serialized = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database configuration encryption failed.');
        }

        return $this->encryptPayload($serialized, self::bindingData($binding), self::BOUND_VERSION);
    }

    public function decryptBound(array $envelope, string $binding): array
    {
        $serialized = $this->decryptPayload($envelope, self::bindingData($binding), self::BOUND_VERSION);

        try {
            $value = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database configuration decryption failed.');
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new DatabaseCredentialException('Database configuration decryption failed.');
        }

        return $value;
    }

    /** Whether a value has the shape of an owner-bound (version 2) envelope. */
    public static function isBoundEnvelope($value): bool
    {
        return is_array($value)
            && array_keys($value) === ['encrypted', 'version', 'algorithm', 'nonce', 'ciphertext', 'tag']
            && $value['encrypted'] === true
            && $value['version'] === self::BOUND_VERSION
            && $value['algorithm'] === self::ALGORITHM
            && is_string($value['nonce']) && is_string($value['ciphertext']) && is_string($value['tag']);
    }

    private static function bindingData(string $binding): string
    {
        if ($binding === '') {
            throw new DatabaseCredentialException('Database configuration binding is invalid.');
        }
        return 'generic-sql-api/database-registry/' . $binding;
    }

    private function encryptPayload(string $plaintext, string $additionalData = '', int $version = self::VERSION): array
    {
        try {
            $nonce = random_bytes(self::NONCE_LENGTH);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::ALGORITHM,
                $this->key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $additionalData,
                self::TAG_LENGTH
            );
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database credential encryption failed.');
        }

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new DatabaseCredentialException('Database credential encryption failed.');
        }

        return [
            'encrypted' => true,
            'version' => $version,
            'algorithm' => self::ALGORITHM,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ];
    }

    private function decryptPayload(array $encryptedConfiguration, string $additionalData = '', int $version = self::VERSION): string
    {
        $this->validateFormat($encryptedConfiguration, $version);

        $nonce = $this->decodeComponent($encryptedConfiguration['nonce']);
        $ciphertext = $this->decodeComponent($encryptedConfiguration['ciphertext']);
        $tag = $this->decodeComponent($encryptedConfiguration['tag']);

        if (strlen($nonce) !== self::NONCE_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            throw new DatabaseCredentialException('Database credential decryption failed.');
        }

        try {
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::ALGORITHM,
                $this->key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $additionalData
            );
        } catch (Throwable $exception) {
            throw new DatabaseCredentialException('Database credential decryption failed.');
        }

        if ($plaintext === false) {
            throw new DatabaseCredentialException('Database credential decryption failed.');
        }

        return $plaintext;
    }

    private function validateFormat(array $encryptedPassword, int $version): void
    {
        if (($encryptedPassword['encrypted'] ?? null) !== true) {
            throw new DatabaseCredentialException('Invalid encrypted database credential configuration.');
        }

        if (($encryptedPassword['version'] ?? null) !== $version) {
            throw new DatabaseCredentialException('Unsupported database credential encryption version.');
        }

        if (($encryptedPassword['algorithm'] ?? null) !== self::ALGORITHM) {
            throw new DatabaseCredentialException('Unsupported database credential encryption algorithm.');
        }

        foreach (['nonce', 'ciphertext', 'tag'] as $field) {
            if (!array_key_exists($field, $encryptedPassword) || !is_string($encryptedPassword[$field])) {
                throw new DatabaseCredentialException('Invalid encrypted database credential configuration.');
            }
        }
    }

    private function decodeComponent(string $value): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new DatabaseCredentialException('Database credential decryption failed.');
        }

        return $decoded;
    }
}
