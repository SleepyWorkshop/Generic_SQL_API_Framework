<?php

/** The registry reads the resolver needs. */
interface DatabaseRegistryReader
{
    /** {defaultDatabase, servers: {id: {name, enabled}}, databases: {id: {name, server, enabled}}}; never decrypts. */
    public function metadata(): array;

    /** Decrypted connection of one server profile. */
    public function serverConnection(string $serverId): array;

    /** Decrypted physical catalog of one database. */
    public function databaseCatalog(string $databaseId): string;
}
