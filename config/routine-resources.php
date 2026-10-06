<?php

/*
 * Deny-by-default registry of callable stored procedures and functions.
 *
 * Only routines registered here can be executed through the `procedure`,
 * `function`, and `tableFunction` actions. Clients send the registry ID in
 * `source.procedure` / `source.function`; the SQL identifier is always built
 * from the server-owned `schema` and `name` below and never from the request.
 * Routines are resolved in the configured database only.
 *
 * Example:
 *
 * 'dbo.RunReport' => [
 *     'type' => 'procedure',          // procedure | function | tableFunction
 *     'schema' => 'dbo',
 *     'name' => 'RunReport',
 *     'access' => 'read',             // write additionally requires data.write
 *     'parameters' => 2,              // exact positional argument count
 *     'roles' => ['read-only', 'data-operator'],
 * ],
 */

return [];
