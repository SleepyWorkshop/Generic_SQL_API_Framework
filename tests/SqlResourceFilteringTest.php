<?php


require_once __DIR__ . '/../app/Repositories/SqlRepository.php';

function filteringAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function filteringFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

class SqlResourceFilteringEngine extends QueryEngine
{
    public array $executions = [];

    public function __construct() {}

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = compact('sql', 'params', 'context');
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) {
            return ['data' => [['TotalRows' => 4]]];
        }
        if (str_contains($sql, 'compatibility_level')) {
            return ['data' => [['CompatibilityLevel' => 150]]];
        }
        return ['executionTime' => 0.1, 'rowsReturned' => 1, 'data' => [['Category' => 'A', 'Sales' => 10]]];
    }
}

class SqlResourceFilteringMetadata extends MetadataRepository
{
    public function __construct() {}

    public function columnExists($table, $column)
    {
        return isset($this->types[strtolower($table)][strtolower($column)]);
    }

    public function getColumnDataType($table, $column)
    {
        return $this->types[strtolower($table)][strtolower($column)] ?? null;
    }

    private array $types = [
        'numericevents' => ['storeddate' => 'int', 'description' => 'varchar'],
        'nativeevents' => ['eventdate' => 'datetime2', 'description' => 'varchar'],
    ];
}

function filteringExecution(?array $filters = null): array
{
    $execution = [
        'columns' => ['Category', 'Sales'],
        'defaultSort' => [['field' => 'Sales', 'direction' => 'DESC']],
    ];
    if ($filters !== null) {
        $execution['filters'] = $filters;
    }
    return ['execution' => $execution];
}

$testDirectory = QUERY_PATH . DIRECTORY_SEPARATOR . 'sql-resource-filter-test-' . bin2hex(random_bytes(6));
$files = [];

try {
    mkdir($testDirectory, 0700, true);

    // Existing Item behavior: no marker, output alias allowlist, prepared value.
    $itemEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($itemEngine, new SqlResourceRegistry()))->execute([
        'resource' => 'item',
        'execution' => ['columns' => ['Item_Code', 'Item_Desc', 'Item_MRP']],
        'filters' => [['field' => 'Item_Desc', 'operator' => 'LIKE', 'value' => "%pen%' OR 1=1 --"]],
    ]);
    $itemData = end($itemEngine->executions);
    filteringAssert(
        str_contains($itemData['sql'], 'SqlResource.[Item_Desc] LIKE ?'),
        'Item output filtering without a marker changed.'
    );
    filteringAssert(
        $itemData['params'] === ["%pen%' OR 1=1 --"]
            && !str_contains($itemData['sql'], "%' OR 1=1 --"),
        'Item injection-style value was not kept as a prepared parameter.'
    );

    $itemNoFilterEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($itemNoFilterEngine, new SqlResourceRegistry()))->execute(['resource' => 'item']);
    $itemNoFilterData = end($itemNoFilterEngine->executions);
    filteringAssert(
        str_contains($itemNoFilterData['sql'], 'FROM ItemMasterTable')
            && !str_contains($itemNoFilterData['sql'], ' WHERE ')
            && $itemNoFilterData['params'] === [],
        'A no-filter Item request gained a filter clause or parameters.'
    );

    // Existing Customer behavior: legacy source marker and integer-date conversion.
    $customerEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($customerEngine, new SqlResourceRegistry()))->execute([
        'resource' => 'customer',
        'execution' => [
            'columns' => ['Cust_Name', 'TotalCustomers'],
            'filters' => [
                'Cust_Name' => ['expression' => 'Cust_Name', 'placement' => 'source'],
                'StDate' => ['expression' => 'StDate', 'placement' => 'source', 'valueType' => 'integer-date'],
            ],
        ],
        'filters' => [
            ['field' => 'Cust_Name', 'operator' => 'LIKE', 'value' => 'A%'],
            ['field' => 'StDate', 'operator' => 'BETWEEN', 'value' => ['2021-04-01', '2022-03-31']],
        ],
    ]);
    $customerData = end($customerEngine->executions);
    filteringAssert(
        str_contains($customerData['sql'], '(Cust_Name) LIKE ?')
            && str_contains($customerData['sql'], '(StDate) BETWEEN ? AND ?')
            && $customerData['params'] === ['A%', 20210401, 20220331],
        'Customer execution-metadata filtering changed.'
    );

    $integerDateFile = $testDirectory . DIRECTORY_SEPARATOR . 'integer-dates.sql';
    file_put_contents(
        $integerDateFile,
        'SELECT StoredDate, Description, SUM(Amount) AS Total FROM NumericEvents '
        . 'GROUP BY StoredDate, Description'
    );
    $files[] = $integerDateFile;

    // Output integer-date filters retain the derived-table behavior while
    // converting semantic frontend dates to integer prepared parameters.
    $outputDateEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($outputDateEngine, new SqlResourceRegistry([], $testDirectory)))->execute([
        'resource' => 'integer-dates',
        'execution' => [
            'columns' => ['StoredDate', 'Description', 'Total'],
            'filters' => [
                'StoredDate' => [
                    'expression' => 'StoredDate',
                    'placement' => 'output',
                    'valueType' => 'integer-date',
                ],
            ],
        ],
        'filters' => [[
            'field' => 'StoredDate',
            'operator' => 'BETWEEN',
            'value' => ['2026-09-30', '2026-10-01'],
        ]],
    ]);
    $outputDateData = end($outputDateEngine->executions);
    filteringAssert(
        str_contains($outputDateData['sql'], 'SqlResource.[StoredDate] BETWEEN ? AND ?')
            && $outputDateData['params'] === [20260930, 20261001],
        'Output integer-date filtering changed.'
    );

    // Semantic date metadata triggers conservative source resolution and uses
    // the real SQL Server column type without a per-resource date mapping.
    $sourceDateRegistry = new SqlResourceRegistry([], $testDirectory);
    $dateMetadata = new SqlResourceFilteringMetadata();
    $sourceDateCases = [
        '=' => ['2026-09-30', '(NumericEvents.StoredDate) = ?', [20260930]],
        'BETWEEN' => [
            ['2026-09-30', '2026-10-01'],
            '(NumericEvents.StoredDate) BETWEEN ? AND ?',
            [20260930, 20261001],
        ],
        'NOT BETWEEN' => [
            ['2026-09-30', '2026-10-01'],
            '(NumericEvents.StoredDate) NOT BETWEEN ? AND ?',
            [20260930, 20261001],
        ],
        'IN' => [
            ['2026-09-30', 20261001],
            '(NumericEvents.StoredDate) IN (?, ?)',
            [20260930, 20261001],
        ],
        'NOT IN' => [
            ['2026-09-30', '20261001'],
            '(NumericEvents.StoredDate) NOT IN (?, ?)',
            [20260930, 20261001],
        ],
    ];
    foreach ($sourceDateCases as $operator => [$value, $sqlFragment, $expectedParams]) {
        $sourceDateEngine = new SqlResourceFilteringEngine();
        (new SqlRepository($sourceDateEngine, $sourceDateRegistry, null, $dateMetadata))->execute([
            'resource' => 'integer-dates',
            'execution' => ['columns' => ['StoredDate', 'Description', 'Total']],
            'filters' => [[
                'field' => 'StoredDate',
                'operator' => $operator,
                'value' => $value,
                'type' => 'daterange',
            ]],
        ]);
        $sourceDateData = end($sourceDateEngine->executions);
        filteringAssert(
            str_contains($sourceDateData['sql'], $sqlFragment)
                && !str_contains($sourceDateData['sql'], 'SqlResource.[NumericEvents.StoredDate]')
                && strpos($sourceDateData['sql'], $sqlFragment) < strpos($sourceDateData['sql'], 'GROUP BY')
                && $sourceDateData['params'] === $expectedParams,
            "Source integer-date {$operator} filtering failed."
        );
    }

    $mixedDateEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($mixedDateEngine, $sourceDateRegistry, null, $dateMetadata))->execute([
        'resource' => 'integer-dates',
        'execution' => ['columns' => ['StoredDate', 'Description', 'Total']],
        'filters' => [
            ['field' => 'StoredDate', 'operator' => 'BETWEEN', 'value' => ['2026-09-30', '2026-10-01'], 'type' => 'daterange'],
            ['field' => 'Description', 'operator' => 'LIKE', 'value' => '%paper%'],
        ],
    ]);
    $mixedDateData = end($mixedDateEngine->executions);
    filteringAssert(
        str_contains($mixedDateData['sql'], '(NumericEvents.StoredDate) BETWEEN ? AND ?')
            && str_contains($mixedDateData['sql'], 'SqlResource.[Description] LIKE ?')
            && $mixedDateData['params'] === [20260930, 20261001, '%paper%'],
        'Integer-date normalization changed normal non-date filtering.'
    );

    $nativeDateFile = $testDirectory . DIRECTORY_SEPARATOR . 'native-dates.sql';
    file_put_contents(
        $nativeDateFile,
        'SELECT EventDate, Description FROM NativeEvents'
    );
    $files[] = $nativeDateFile;
    $nativeDateRegistry = new SqlResourceRegistry([], $testDirectory);
    $nativeDateEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($nativeDateEngine, $nativeDateRegistry, null, $dateMetadata))->execute([
        'resource' => 'native-dates',
        'execution' => ['columns' => ['EventDate', 'Description']],
        'filters' => [[
            'field' => 'EventDate',
            'operator' => 'BETWEEN',
            'value' => ['2026-09-30', '2026-10-01'],
            'type' => 'daterange',
        ]],
    ]);
    $nativeDateData = end($nativeDateEngine->executions);
    filteringAssert(
        str_contains($nativeDateData['sql'], '(NativeEvents.EventDate) BETWEEN ? AND ?')
            && $nativeDateData['params'] === ['2026-09-30', '2026-10-01'],
        'Native SQL Server date values must remain ISO prepared parameters.'
    );

    $aliasedDateFile = $testDirectory . DIRECTORY_SEPARATOR . 'aliased-date.sql';
    file_put_contents(
        $aliasedDateFile,
        'SELECT E.StoredDate AS ReportDate, Description FROM NumericEvents E'
    );
    $files[] = $aliasedDateFile;
    $aliasedDateRegistry = new SqlResourceRegistry([], $testDirectory);
    $aliasedDateEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($aliasedDateEngine, $aliasedDateRegistry, null, $dateMetadata))->execute([
        'resource' => 'aliased-date',
        'execution' => ['columns' => ['ReportDate', 'Description']],
        'filters' => [[
            'field' => 'ReportDate',
            'operator' => '=',
            'value' => '2026-09-30',
            'type' => 'date',
        ]],
    ]);
    $aliasedDateData = end($aliasedDateEngine->executions);
    filteringAssert(
        str_contains($aliasedDateData['sql'], '(E.StoredDate) = ?')
            && $aliasedDateData['params'] === [20260930],
        'An aliased direct date projection did not resolve to its physical source column.'
    );

    $outputDateFile = $testDirectory . DIRECTORY_SEPARATOR . 'derived-date.sql';
    file_put_contents(
        $outputDateFile,
        'SELECT CONVERT(date, EventDate) AS ReportDate, Description FROM NativeEvents'
    );
    $files[] = $outputDateFile;
    $outputDateRegistry = new SqlResourceRegistry([], $testDirectory);
    $derivedDateEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($derivedDateEngine, $outputDateRegistry, null, $dateMetadata))->execute([
        'resource' => 'derived-date',
        'execution' => ['columns' => ['ReportDate', 'Description']],
        'filters' => [[
            'field' => 'ReportDate',
            'operator' => '=',
            'value' => '2026-09-30',
            'type' => 'date',
        ]],
    ]);
    $derivedDateData = end($derivedDateEngine->executions);
    filteringAssert(
        str_contains($derivedDateData['sql'], 'SqlResource.[ReportDate] = ?')
            && $derivedDateData['params'] === ['2026-09-30'],
        'A genuine derived output filter was incorrectly moved into the source query.'
    );

    $sourceOnlyFile = $testDirectory . DIRECTORY_SEPARATOR . 'source-only-date.sql';
    file_put_contents(
        $sourceOnlyFile,
        'SELECT Description, SUM(Amount) AS Total FROM NumericEvents E GROUP BY Description'
    );
    $files[] = $sourceOnlyFile;
    $sourceOnlyRegistry = new SqlResourceRegistry([], $testDirectory);
    $sourceOnlyEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($sourceOnlyEngine, $sourceOnlyRegistry, null, $dateMetadata))->execute([
        'resource' => 'source-only-date',
        'execution' => [
            'columns' => ['Description', 'Total'],
            'filters' => ['StoredDate' => ['placement' => 'source']],
        ],
        'filters' => [[
            'field' => 'StoredDate',
            'operator' => 'BETWEEN',
            'value' => ['2026-09-30', '2026-10-01'],
            'type' => 'daterange',
        ]],
    ]);
    $sourceOnlyData = end($sourceOnlyEngine->executions);
    filteringAssert(
        str_contains($sourceOnlyData['sql'], '(E.StoredDate) BETWEEN ? AND ?')
            && strpos($sourceOnlyData['sql'], 'E.StoredDate') < strpos($sourceOnlyData['sql'], 'GROUP BY')
            && $sourceOnlyData['params'] === [20260930, 20261001],
        'An auto-resolved source-only date filter was not injected before GROUP BY.'
    );

    $invalidDate = filteringFailure(
        fn () => (new SqlRepository(
            new SqlResourceFilteringEngine(),
            $sourceDateRegistry,
            null,
            $dateMetadata))->execute([
            'resource' => 'integer-dates',
            'execution' => ['columns' => ['StoredDate', 'Description', 'Total']],
            'filters' => [[
                'field' => 'StoredDate',
                'operator' => '=',
                'value' => '2026-02-30',
                'type' => 'date',
            ]],
        ]),
        'An invalid semantic date was accepted.'
    );
    filteringAssert(
        $invalidDate instanceof ApiRequestException
            && $invalidDate->getErrorCode() === 'INVALID_SQL_RUNTIME_VALUE',
        'Invalid semantic date returned the wrong error.'
    );

    $complexSql = <<<'SQL'
SELECT TOP 10
    CAT.Cat_Desc AS Category,
    ROUND(SUM(BIL.Item_Rate) / 100000, 0) AS Sales
FROM BillDetTable BIL, CategoryTable CAT
WHERE BIL.Cat_Code = CAT.Cat_Code
  AND BIL.Bill_NETT > 0
GROUP BY CAT.Cat_Desc
ORDER BY Sales DESC
SQL;
    $complexFile = $testDirectory . DIRECTORY_SEPARATOR . 'complex.sql';
    file_put_contents($complexFile, $complexSql);
    $files[] = $complexFile;
    $mappedFilters = [
        'BillDate' => [
            'expression' => 'BIL.Bill_Date',
            'placement' => 'source',
            'valueType' => 'integer-date',
        ],
        'CategorySearch' => [
            'expression' => 'CAT.Cat_Desc',
            'placement' => 'source',
        ],
        'DeletedAt' => [
            'expression' => 'BIL.DeletedAt',
            'placement' => 'source',
        ],
        'MinimumSales' => [
            'expression' => 'SUM(BIL.Item_Rate)',
            'placement' => 'having',
        ],
    ];
    $complexRegistry = new SqlResourceRegistry([], $testDirectory);
    $complexExecution = filteringExecution($mappedFilters);
    $resolved = $complexRegistry->resolve('complex', $complexExecution['execution']);
    filteringAssert(
        array_keys($resolved['filters']) === ['category', 'sales', 'billdate', 'categorysearch', 'deletedat', 'minimumsales']
            && $resolved['filters']['billdate']['valueType'] === 'integer-date',
        'Explicit filter mapping was not normalized correctly.'
    );

    $complexEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($complexEngine, $complexRegistry))->execute([
        'resource' => 'complex',
        ...$complexExecution,
        'filters' => [
            ['field' => 'BillDate', 'operator' => 'BETWEEN', 'value' => ['20210401', '20220331']],
            ['field' => 'CategorySearch', 'operator' => 'LIKE', 'value' => '%food%'],
            ['field' => 'DeletedAt', 'operator' => 'IS NULL'],
            ['field' => 'MinimumSales', 'operator' => '>', 'value' => 500000],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 5],
    ]);
    $complexData = end($complexEngine->executions);
    $complexCount = array_values(array_filter(
        $complexEngine->executions,
        fn (array $execution): bool => str_contains($execution['sql'], 'COUNT(*) AS TotalRows')
    ))[0];
    filteringAssert(
        str_contains($complexData['sql'], 'AND ((BIL.Bill_Date) BETWEEN ? AND ?')
            && str_contains($complexData['sql'], '(CAT.Cat_Desc) LIKE ?')
            && str_contains($complexData['sql'], '(BIL.DeletedAt) IS NULL')
            && strpos($complexData['sql'], 'BIL.Bill_Date') < strpos($complexData['sql'], 'GROUP BY'),
        'Explicit mapped WHERE filters were not inserted before grouping.'
    );
    filteringAssert(
        str_contains($complexData['sql'], 'HAVING (SUM(BIL.Item_Rate)) > ?')
            && strpos($complexData['sql'], 'HAVING') > strpos($complexData['sql'], 'GROUP BY')
            && strpos($complexData['sql'], 'HAVING') < strpos($complexData['sql'], 'ORDER BY Sales DESC'),
        'Explicit aggregate filter was not placed in HAVING.'
    );
    $expectedParams = [20210401, 20220331, '%food%', 500000];
    filteringAssert(
        $complexData['params'] === $expectedParams
            && $complexCount['params'] === $expectedParams
            && str_contains($complexData['sql'], 'FETCH NEXT 5 ROWS ONLY'),
        'Mapped filter parameters or pagination changed.'
    );

    filteringFailure(
        fn () => (new SqlRepository(new SqlResourceFilteringEngine(), $complexRegistry))->execute([
            'resource' => 'complex',
            ...$complexExecution,
            'filters' => [['field' => 'NotApproved', 'operator' => '=', 'value' => 1]],
        ]),
        'An unauthorized logical filter field was accepted.'
    );

    $complexNoFilterEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($complexNoFilterEngine, $complexRegistry))->execute(['resource' => 'complex', ...$complexExecution]);
    $complexNoFilterData = end($complexNoFilterEngine->executions);
    filteringAssert(
        $complexNoFilterData['sql'] === $complexSql
            && $complexNoFilterData['params'] === [],
        'A no-filter mapped resource was unnecessarily rewritten.'
    );

    $topEngine = new SqlResourceFilteringEngine();
    $topResult = (new SqlRepository($topEngine, $complexRegistry))->execute([
        'resource' => 'complex',
        ...$complexExecution,
        'pagination' => ['page' => 1, 'pageSize' => 10],
    ]);
    filteringAssert(
        count($topEngine->executions) === 1
            && $topResult['totalRows'] === $topResult['rowsReturned'],
        'No-filter TOP optimization changed.'
    );

    $havingFile = $testDirectory . DIRECTORY_SEPARATOR . 'having.sql';
    file_put_contents($havingFile, 'SELECT Category AS Category, SUM(Amount) AS Sales FROM Sales GROUP BY Category HAVING COUNT(*) > 1 ORDER BY Sales DESC');
    $files[] = $havingFile;
    $havingRegistry = new SqlResourceRegistry([], $testDirectory);
    $havingExecution = filteringExecution([
        'MinimumSales' => ['expression' => 'SUM(Amount)', 'placement' => 'having'],
    ]);
    $havingEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($havingEngine, $havingRegistry))->execute([
        'resource' => 'having',
        ...$havingExecution,
        'filters' => [['field' => 'MinimumSales', 'operator' => '>=', 'value' => 100]],
    ]);
    filteringAssert(
        str_contains(end($havingEngine->executions)['sql'], 'HAVING COUNT(*) > 1  AND ((SUM(Amount)) >= ?)'),
        'Mapped HAVING filter was not appended to an existing HAVING clause.'
    );

    $cteFile = $testDirectory . DIRECTORY_SEPARATOR . 'cte.sql';
    file_put_contents($cteFile, 'WITH Recent AS (SELECT Id, CreatedAt FROM Events) SELECT Id AS Category, 0 AS Sales FROM Recent ORDER BY Category');
    $files[] = $cteFile;
    $cteRegistry = new SqlResourceRegistry([], $testDirectory);
    $cteExecution = filteringExecution([
        'CreatedAfter' => ['expression' => 'Recent.CreatedAt', 'placement' => 'source'],
    ]);
    $cteEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($cteEngine, $cteRegistry))->execute([
        'resource' => 'cte',
        ...$cteExecution,
        'filters' => [['field' => 'CreatedAfter', 'operator' => '>', 'value' => '2026-01-01']],
    ]);
    $cteData = end($cteEngine->executions);
    filteringAssert(
        str_starts_with($cteData['sql'], 'WITH Recent AS')
            && str_contains($cteData['sql'], 'WHERE (Recent.CreatedAt) > ?'),
        'Mapped CTE filtering did not target the main SELECT.'
    );

    $unionFile = $testDirectory . DIRECTORY_SEPARATOR . 'union.sql';
    file_put_contents($unionFile, 'SELECT Id AS Category, 0 AS Sales FROM A UNION ALL SELECT Id, 0 FROM B');
    $files[] = $unionFile;
    $unionRegistry = new SqlResourceRegistry([], $testDirectory);
    $unionExecution = filteringExecution([
        'SourceId' => ['expression' => 'Id', 'placement' => 'source'],
    ]);
    $unionFailure = filteringFailure(
        fn () => (new SqlRepository(new SqlResourceFilteringEngine(), $unionRegistry))->execute([
            'resource' => 'union',
            ...$unionExecution,
            'filters' => [['field' => 'SourceId', 'operator' => '=', 'value' => 1]],
        ]),
        'Ambiguous set-operation WHERE filtering was accepted.'
    );
    filteringAssert(
        $unionFailure instanceof ApiRequestException
            && $unionFailure->getErrorCode() === 'INVALID_SQL_RUNTIME_FILTER',
        'Ambiguous set-operation filtering returned the wrong error.'
    );

    $unionOutputRegistry = $unionRegistry;
    $unionOutputExecution = filteringExecution([
        'LogicalCategory' => ['expression' => 'Category', 'placement' => 'output'],
    ]);
    $unionOutputEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($unionOutputEngine, $unionOutputRegistry))->execute([
        'resource' => 'union',
        ...$unionOutputExecution,
        'filters' => [['field' => 'LogicalCategory', 'operator' => '=', 'value' => "1' OR 1=1 --"]],
    ]);
    $unionOutputData = end($unionOutputEngine->executions);
    filteringAssert(
        str_contains($unionOutputData['sql'], 'SqlResource.[Category] = ?')
            && $unionOutputData['params'] === ["1' OR 1=1 --"],
        'Safe output filtering over a set operation failed.'
    );

    $mixedOrFailure = filteringFailure(
        fn () => (new SqlRepository(new SqlResourceFilteringEngine(), $complexRegistry))->execute([
            'resource' => 'complex',
            ...$complexExecution,
            'filterLogic' => 'OR',
            'filters' => [
                ['field' => 'CategorySearch', 'operator' => 'LIKE', 'value' => 'A%'],
                ['field' => 'MinimumSales', 'operator' => '>', 'value' => 10],
            ],
        ]),
        'OR filters spanning WHERE and HAVING were accepted.'
    );
    filteringAssert(
        $mixedOrFailure instanceof ApiRequestException
            && $mixedOrFailure->getErrorCode() === 'INVALID_SQL_RUNTIME_FILTER',
        'Mixed-location OR filters returned the wrong error.'
    );

    $derivedFile = $testDirectory . DIRECTORY_SEPARATOR . 'derived.sql';
    file_put_contents($derivedFile, 'SELECT D.Id AS Category, ROW_NUMBER() OVER (ORDER BY D.CreatedAt) AS Sales FROM (SELECT Id, CreatedAt FROM Events WHERE Active = 1) AS D ORDER BY Category');
    $files[] = $derivedFile;
    $derivedRegistry = new SqlResourceRegistry([], $testDirectory);
    $derivedExecution = filteringExecution([
        'CreatedAfter' => ['expression' => 'D.CreatedAt', 'placement' => 'source'],
    ]);
    // A derived table is not a top-level source of the authored statement, so a
    // client mapping to its alias cannot be placed and is rejected.
    $derivedFailure = filteringFailure(
        fn () => (new SqlRepository(new SqlResourceFilteringEngine(), $derivedRegistry))->execute([
            'resource' => 'derived',
            ...$derivedExecution,
            'filters' => [['field' => 'CreatedAfter', 'operator' => '>', 'value' => '2026-01-01']],
        ]),
        'A mapped filter on a derived-table alias was accepted.'
    );
    filteringAssert(
        $derivedFailure instanceof ApiRequestException
            && $derivedFailure->getErrorCode() === 'INVALID_SQL_RUNTIME_FILTER',
        'Derived-table mapped filter returned the wrong error.'
    );

    $derivedJoinFile = $testDirectory . DIRECTORY_SEPARATOR . 'derived-join.sql';
    file_put_contents($derivedJoinFile, 'SELECT D.Id AS Category, ROW_NUMBER() OVER (ORDER BY E.CreatedAt) AS Sales FROM (SELECT Id FROM Events WHERE Active = 1) AS D JOIN Events AS E ON E.Id = D.Id ORDER BY Category');
    $files[] = $derivedJoinFile;
    $derivedJoinRegistry = new SqlResourceRegistry([], $testDirectory);
    $derivedJoinEngine = new SqlResourceFilteringEngine();
    (new SqlRepository($derivedJoinEngine, $derivedJoinRegistry))->execute([
        'resource' => 'derived-join',
        ...filteringExecution(['CreatedAfter' => ['expression' => 'E.CreatedAt', 'placement' => 'source']]),
        'filters' => [['field' => 'CreatedAfter', 'operator' => '>', 'value' => '2026-01-01']],
    ]);
    $derivedData = end($derivedJoinEngine->executions);
    filteringAssert(
        str_contains($derivedData['sql'], 'WHERE (E.CreatedAt) > ?')
            && str_contains($derivedData['sql'], 'ROW_NUMBER() OVER (ORDER BY E.CreatedAt)')
            && substr_count($derivedData['sql'], 'WHERE') === 2,
        'Nested WHERE/window ORDER BY confused top-level filter insertion.'
    );

    foreach ([
        ['Bad' => ['expression' => 'BIL.Id; DELETE FROM BIL', 'placement' => 'source']],
        ['Bad' => ['expression' => 'BIL.Id', 'placement' => 'join']],
        ['Bad' => ['expression' => 'BIL.Id', 'placement' => 'source', 'unknown' => true]],
    ] as $index => $invalidFilters) {
        filteringFailure(
            fn () => (new SqlResourceRegistry([], $testDirectory))->resolve(
                'complex',
                filteringExecution($invalidFilters)['execution']
            ),
            "Invalid filter mapping {$index} was accepted."
        );
    }

    echo "SQL resource filtering tests passed.\n";
} finally {
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($testDirectory);
}
