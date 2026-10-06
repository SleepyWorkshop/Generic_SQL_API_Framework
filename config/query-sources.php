<?php

/*
 * Deny-by-default registry of tables and views readable through JSON Query
 * Mode (select, joins, subqueries, set operations, CTE bodies), metadata
 * listings, and SQL Resource runtime source-filter resolution.
 *
 * Keys are unqualified table or view names in the configured database and
 * match case-insensitively, like the default SQL Server collation. An entry
 * may restrict access with `roles`: role IDs and/or `frontend-access` (any
 * principal with frontend access). Without `roles`, every principal that is
 * already authorized for the read action may use the source.
 *
 * The entries are the physical sources confirmed by the frontend query-source
 * inventory: those read directly by the shipped frontend reports/dashboards
 * and those used by the tracked SQL Resources they reference.
 */

return [
    // Frontend reports (customer, item) and the item dashboard.
    'CustomerTable' => [],
    'ItemMasterTable' => [],

    // Tracked SQL Resources in queries/widgets (runtime source filters).
    'BillDetTable' => [],
    'BillMastTable' => [],
    'CategoryTable' => [],
    'PurMastTable' => [],
];
