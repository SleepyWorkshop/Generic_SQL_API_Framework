<?php

require_once __DIR__ . '/DatabaseRegistry.php';
require_once __DIR__ . '/DatabaseContext.php';
require_once __DIR__ . '/DatabaseContextResolver.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

/**
 * A logical database id named by a request, and where it was named. It carries
 * no physical name, host, or credential: those come only from the registry
 * when the reference is resolved.
 */
final class DatabaseReference
{
    private function __construct(public readonly string $id, public readonly string $path) {}

    /** @throws ApiRequestException when the value is not a database id */
    public static function fromRequest($value, string $path): self
    {
        if (!DatabaseRegistry::isValidId($value)) {
            throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [
                ['path' => $path, 'message' => 'Database must be a configured database id.'],
            ]);
        }
        return new self($value, $path);
    }

    /** The configured default database, used when a request names none. */
    public static function defaultDatabase(string $id): self
    {
        return new self($id, 'database');
    }

    /**
     * Resolve through the registry. Resolution errors report the request path
     * at which the database was named.
     */
    public function resolve(DatabaseContextResolver $resolver): DatabaseContext
    {
        try {
            return $resolver->resolve($this->id);
        } catch (ApiRequestException $exception) {
            throw $this->atPath($exception);
        }
    }

    public function atPath(ApiRequestException $exception): ApiRequestException
    {
        $details = array_map(fn (array $detail): array => ['path' => $this->path] + $detail, array_map(
            static function (array $detail): array { unset($detail['path']); return $detail; },
            $exception->getDetails()
        ));
        return new ApiRequestException($exception->getMessage(), $exception->getErrorCode(), $details, $exception->getStatusCode());
    }
}
