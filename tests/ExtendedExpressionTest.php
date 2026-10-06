<?php

require_once __DIR__ . '/support/PermissiveQuerySourcePolicy.php';

require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/Query/SelectBuilder.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';

class ExtendedExpressionEngine extends QueryEngine
{
    public array $executions = [];

    public function __construct() {}

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = ['sql' => $sql, 'params' => $params, 'context' => $context];
        if (str_contains($sql, 'compatibility_level')) {
            return ['data' => [['CompatibilityLevel' => 150]]];
        }
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) {
            return ['data' => [['TotalRows' => 0]]];
        }
        return ['data' => [], 'rowsReturned' => 0];
    }
}

class ExtendedExpressionMetadata extends MetadataRepository
{
    public function __construct() {}
    public function tableExists($table) { return true; }
    public function columnExists($table, $column) { return $column !== 'Missing'; }
    public function getColumnDataType($table, $column) { return 'varchar'; }
    public function getColumns($table) { return ['data' => [['COLUMN_NAME' => 'Item_Code']]]; }
}

function extendedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function extendedContains(string $needle, string $haystack, string $message): void
{
    extendedAssert(strpos($haystack, $needle) !== false, $message . "\nMissing: {$needle}\nSQL: {$haystack}");
}

function extendedInvalid(QueryRequestValidator $validator, array $request, ?string $path = null): void
{
    try {
        $validator->validate($request);
    } catch (ApiRequestException $exception) {
        extendedAssert($exception->getErrorCode() === 'INVALID_REQUEST', 'Wrong recursive validation error code.');
        if ($path !== null) {
            extendedAssert(
                in_array($path, array_column($exception->getDetails(), 'path'), true),
                "Recursive validation did not reject expected path {$path}."
            );
        }
        return;
    }
    throw new RuntimeException('Unsafe recursive expression request was accepted.');
}

$field = fn (string $name): array => ['field' => $name];
$literal = fn ($value): array => ['literal' => $value];
$binary = fn (array $left, string $operator, array $right): array => [
    'expression' => ['left' => $left, 'operator' => $operator, 'right' => $right],
];
$convert = fn (string $name): array => [
    'function' => 'CONVERT', 'datatype' => 'VARCHAR', 'field' => $field($name),
];
$substring = fn (string $name, int $start, int $length): array => [
    'function' => 'SUBSTRING', 'field' => $convert($name),
    'start' => $start, 'length' => $length,
];
$sum = fn (array $expression): array => ['function' => 'SUM', 'field' => $expression];
$round = fn (array $expression, int $precision = 0): array => [
    'function' => 'ROUND', 'field' => $expression, 'precision' => $precision,
];

$validator = new QueryRequestValidator();
$normalizer = new QueryRequestNormalizer();
$engine = new ExtendedExpressionEngine();
$metadata = new ExtendedExpressionMetadata();
$builder = new SelectBuilder($engine, $metadata, sourcePolicy: new PermissiveQuerySourcePolicy());
$build = function (array $request, bool $unionBranch = false) use ($validator, $normalizer, $builder): array {
    $validator->validate($request);
    return $builder->build($normalizer->normalize($request), $unionBranch);
};

// Query 1: ROUND(arithmetic).
$query1 = $build([
    'action' => 'select', 'source' => ['table' => 'ItemMasterTable'],
    'fields' => [
        'Item_Code', 'Item_Desc', 'Sale_Rate', 'Item_MRP', 'Std_Vat', 'cl_stock',
        $round($binary($field('Cl_Stock'), '*', $field('Sale_Rate')), 2)
            + ['alias' => 'stock_value'],
    ],
]);
extendedContains('ROUND((Cl_Stock * Sale_Rate), 2) AS [stock_value]', $query1['sql'], 'Query 1 was not generated.');
extendedAssert($query1['params'] === [], 'Query 1 unexpectedly created parameters.');

$legacyArithmetic = [
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [['expression' => ['left' => 'Amount', 'operator' => '+', 'right' => 2]]],
];
$validator->validate($legacyArithmetic);
$legacyAst = $normalizer->normalize($legacyArithmetic)['columns'][0]['node'];
extendedAssert(
    $legacyAst['type'] === 'binary'
        && $legacyAst['left'] === ['type' => 'field', 'name' => 'Amount']
        && $legacyAst['right'] === ['type' => 'literal', 'value' => 2],
    'Legacy arithmetic did not normalize to the canonical AST.'
);

$nestedDate = $build([
    'action' => 'select', 'source' => ['table' => 'ItemMasterTable'],
    'fields' => [[
        'function' => 'YEAR', 'field' => ['function' => 'GETDATE'], 'alias' => 'CurrentYear',
    ]],
]);
extendedContains('YEAR(GETDATE()) AS [CurrentYear]', $nestedDate['sql'], 'YEAR(GETDATE()) nesting failed.');

// Query 2: aggregate over arithmetic.
$query2 = $build([
    'action' => 'select', 'source' => ['table' => 'ItemMasterTable'],
    'fields' => [
        ['function' => 'COUNT', 'field' => 'Item_Code', 'alias' => 'TotalItems'],
        ['function' => 'MIN', 'field' => 'Sale_Rate', 'alias' => 'MinimumSP'],
        ['function' => 'MAX', 'field' => 'Sale_Rate', 'alias' => 'MaximumSP'],
        $sum($binary($field('Sale_Rate'), '*', $field('Cl_Stock'))) + ['alias' => 'StockValue'],
    ],
]);
extendedContains('SUM((Sale_Rate * Cl_Stock)) AS [StockValue]', $query2['sql'], 'Query 2 was not generated.');

// Query 3: scalar over aggregate/arithmetic with existing join/filter/group/sort/TOP.
$sales = $round($binary($sum($field('BIL.Item_Rate')), '/', $literal(100000)), 0);
$query3 = $build([
    'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
    'fields' => [
        ['field' => 'CAT.Cat_Desc', 'alias' => 'Category'],
        $sales + ['alias' => 'Sales'],
    ],
    'joins' => [[
        'type' => 'INNER', 'source' => ['table' => 'CategoryTable', 'alias' => 'CAT'],
        'on' => ['left' => 'BIL.Cat_Code', 'operator' => '=', 'right' => 'CAT.Cat_Code'],
    ]],
    'filters' => [
        ['field' => 'BIL.Bill_NETT', 'operator' => '>', 'value' => 0],
        ['field' => 'BIL.Bill_Date', 'operator' => 'BETWEEN', 'value' => [20210401, 20220331]],
    ],
    'groupBy' => ['CAT.Cat_Desc'],
    'sort' => [['field' => 'Sales', 'direction' => 'DESC']],
    'limit' => 10,
]);
extendedContains('ROUND((SUM(BIL.Item_Rate) / 100000), 0) AS [Sales]', $query3['sql'], 'Query 3 expression was not generated.');
extendedContains('TOP 10', $query3['sql'], 'Query 3 TOP was lost.');
extendedAssert($query3['params'] === [0, 20210401, 20220331], 'Query 3 runtime parameter order is incorrect.');

// Query 4: the same nested function in SELECT, GROUP BY, and ORDER BY.
$month = $substring('BILL_DATE', 5, 2);
$query4 = $build([
    'action' => 'select', 'source' => ['table' => 'billmasttable'],
    'fields' => [
        $month + ['alias' => 'Month'],
        $round($sum($field('BILL_AMT')), 0) + ['alias' => 'Sales'],
    ],
    'filters' => [['field' => 'Bill_Date', 'operator' => 'BETWEEN', 'value' => [20210401, 20220331]]],
    'groupBy' => [$month],
    'sort' => [['expression' => $month, 'direction' => 'DESC']],
]);
extendedContains('SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) AS [Month]', $query4['sql'], 'Query 4 projection was not generated.');
extendedContains('GROUP BY SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2)', $query4['sql'], 'Query 4 grouping was not generated.');
extendedContains('ORDER BY SUBSTRING(CONVERT(VARCHAR, BILL_DATE), 5, 2) DESC', $query4['sql'], 'Query 4 ordering was not generated.');

// Query 5: multiple expression grouping and ordering entries.
$yearPart = $substring('GIN_DATE', 1, 4);
$monthPart = $substring('GIN_DATE', 5, 2);
$query5 = $build([
    'action' => 'select', 'source' => ['table' => 'PurMastTable'],
    'fields' => [
        $monthPart + ['alias' => 'Month'],
        $round($sum($field('Inv_Value')), 0) + ['alias' => 'Purchases'],
    ],
    'filters' => [['field' => 'GIN_DATE', 'operator' => 'BETWEEN', 'value' => [20210401, 20220331]]],
    'groupBy' => [$yearPart, $monthPart],
    'sort' => [
        ['expression' => $yearPart, 'direction' => 'ASC'],
        ['expression' => $monthPart, 'direction' => 'DESC'],
    ],
]);
extendedContains('GROUP BY SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 1, 4), SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 5, 2)', $query5['sql'], 'Query 5 grouping was not generated.');
extendedContains('ORDER BY SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 1, 4) ASC, SUBSTRING(CONVERT(VARCHAR, GIN_DATE), 5, 2) DESC', $query5['sql'], 'Query 5 ordering was not generated.');

// Query 6: generic HAVING expression and deterministic expression parameters.
$query6 = $build([
    'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
    'fields' => [
        $substring('BIL.Bill_Date', 5, 2) + ['alias' => 'Month'],
        ['field' => 'CAT.Cat_Desc', 'alias' => 'Category'],
        $round($binary($sum($field('BIL.Item_Rate')), '/', $literal(1000)), 0) + ['alias' => 'Sales'],
    ],
    'joins' => [[
        'type' => 'INNER', 'source' => ['table' => 'CategoryTable', 'alias' => 'CAT'],
        'on' => ['left' => 'BIL.Cat_Code', 'operator' => '=', 'right' => 'CAT.Cat_Code'],
    ]],
    'filters' => [['field' => 'BIL.Bill_Date', 'operator' => 'BETWEEN', 'value' => [20210401, 20220331]]],
    'groupBy' => [$substring('BIL.Bill_Date', 5, 2), 'CAT.Cat_Desc'],
    'having' => [[
        'expression' => $round($binary($sum($field('BIL.Item_Rate')), '/', $literal(1000)), 0),
        'operator' => '>', 'value' => 15,
    ]],
    'sort' => [
        ['field' => 'Month', 'direction' => 'ASC'],
        ['field' => 'Category', 'direction' => 'ASC'],
    ],
]);
extendedContains('HAVING ROUND((SUM(BIL.Item_Rate) / 1000), 0) > ?', $query6['sql'], 'Query 6 HAVING was not generated.');
extendedAssert(
    $query6['params'] === [20210401, 20220331, 15],
    'Query 6 prepared parameter order is incorrect.'
);

// SQL Server/ODBC: literal divisors must not create untyped parameters in
// aggregate expressions, while runtime WHERE/HAVING values stay bound.
$odbcDivision = $binary($sum($field('BIL.Item_Rate')), '/', $literal(1000));
$divisionQuery = $build([
    'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
    'fields' => [$odbcDivision + ['alias' => 'ScaledSales']],
]);
extendedContains('(SUM(BIL.Item_Rate) / 1000) AS [ScaledSales]', $divisionQuery['sql'], 'Numeric divisor was left as an ODBC parameter.');
extendedAssert($divisionQuery['params'] === [], 'Structural numeric divisor added a parameter.');

$odbcSales = $round($odbcDivision, 0);
$odbcMonth = $substring('BIL.Bill_Date', 5, 2);
$odbcRequest = [
    'action' => 'select', 'source' => ['table' => 'BillDetTable', 'alias' => 'BIL'],
    'fields' => [$odbcMonth + ['alias' => 'Month'], ['field' => 'CAT.Cat_Desc', 'alias' => 'Category'], $odbcSales + ['alias' => 'Sales']],
    'joins' => [[
        'type' => 'INNER', 'source' => ['table' => 'CategoryTable', 'alias' => 'CAT'],
        'on' => ['left' => 'BIL.Cat_Code', 'operator' => '=', 'right' => 'CAT.Cat_Code'],
    ]],
    'filters' => [['field' => 'BIL.Bill_Date', 'operator' => 'BETWEEN', 'value' => [20210401, 20220331]]],
    'groupBy' => [$odbcMonth, 'CAT.Cat_Desc'],
    'having' => [['expression' => $odbcSales, 'operator' => '>', 'value' => 15]],
    'sort' => [['field' => 'Month', 'direction' => 'ASC'], ['field' => 'Category', 'direction' => 'ASC']],
    'pagination' => ['page' => 1, 'pageSize' => 10],
];
$odbcEngine = new ExtendedExpressionEngine();
$odbcBuilder = new SelectBuilder($odbcEngine, new ExtendedExpressionMetadata(), sourcePolicy: new PermissiveQuerySourcePolicy());
$validator->validate($odbcRequest);
$odbcBuilt = $odbcBuilder->build($normalizer->normalize($odbcRequest));
$odbcCount = $odbcEngine->executions[0];
extendedAssert($odbcCount['context']['queryPhase'] === 'pagination_count', 'ODBC regression did not exercise pagination count generation.');
foreach ([$odbcBuilt, $odbcCount] as $query) {
    extendedContains('ROUND((SUM(BIL.Item_Rate) / 1000), 0) AS [Sales]', $query['sql'], 'ROUND expression or structural precision is incorrect.');
    extendedContains('HAVING ROUND((SUM(BIL.Item_Rate) / 1000), 0) > ?', $query['sql'], 'HAVING divisor or runtime comparison binding is incorrect.');
    extendedContains('BIL.Bill_Date BETWEEN ? AND ?', $query['sql'], 'Runtime date bounds were interpolated.');
    extendedAssert($query['params'] === [20210401, 20220331, 15], 'Count/data parameters must contain only runtime bounds and HAVING value.');
    extendedAssert(substr_count($query['sql'], '?') === count($query['params']), 'Count/data SQL parameter count is inconsistent.');
}
extendedContains('SELECT COUNT(*) AS TotalRows', $odbcCount['sql'], 'Regression did not capture the count SQL wrapper.');
extendedAssert(!str_contains($odbcCount['sql'], ' / ?'), 'Count query retained untyped divisor parameters.');

$decimalDivision = $build([
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [$binary($field('Amount'), '/', $literal(1000.0)) + ['alias' => 'DecimalScaled']],
]);
extendedContains('(Amount / 1000.0)', $decimalDivision['sql'], 'Floating divisor lost its decimal spelling.');
extendedAssert($decimalDivision['params'] === [], 'Finite floating divisor was parameterized.');

// Numeric literals elsewhere remain parameters; numeric strings and malicious
// text must never be promoted to structural SQL numbers.
foreach (['1000', '1000); DROP TABLE X;--', 'BIL.Item_Rate', null, true] as $value) {
    $query = $build([
        'action' => 'select', 'source' => ['table' => 'Items'],
        'fields' => [$binary($field('Amount'), '/', $literal($value)) + ['alias' => 'Value']],
    ]);
    extendedContains('(Amount / ?)', $query['sql'], 'A non-numeric literal was emitted as structural SQL.');
    extendedAssert($query['params'] === [$value], 'A non-numeric expression value stopped being bound.');
    extendedAssert(!str_contains($query['sql'], 'DROP TABLE'), 'Malicious expression text became SQL syntax.');
}
$ordinaryNumericLiteral = $build([
    'action' => 'select', 'source' => ['table' => 'Items'], 'fields' => [$literal(1000) + ['alias' => 'Value']],
]);
extendedContains('? AS [Value]', $ordinaryNumericLiteral['sql'], 'Standalone runtime numeric literal was globally inlined.');
extendedAssert($ordinaryNumericLiteral['params'] === [1000], 'Standalone numeric literal stopped being bound.');

foreach (['0', '0); DROP TABLE X;--', true, ['field' => 'Amount']] as $precision) {
    extendedInvalid($validator, [
        'action' => 'select', 'source' => ['table' => 'Items'],
        'fields' => [['function' => 'ROUND', 'field' => $odbcDivision, 'precision' => $precision]],
    ], 'fields.0.precision');
}
extendedInvalid($validator, [
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [$binary($field('Amount'), '/', $literal(['sql' => '1000); DROP TABLE X']))],
]);
foreach ([INF, -INF, NAN] as $divisor) {
    $unusedParams = [];
    try {
        (new SqlExpressionBuilder())->renderNode([
            'type' => 'binary', 'operator' => '/',
            'left' => ['type' => 'field', 'name' => 'Amount'],
            'right' => ['type' => 'literal', 'value' => $divisor],
        ], $unusedParams);
        throw new RuntimeException('Non-finite structural numeric divisor was accepted.');
    } catch (Exception $exception) {
        extendedAssert($exception->getMessage() === 'Numeric expression divisor must be finite.', 'Structural numeric guard returned an unexpected error.');
    }
}

// Recursive CASE, unary, NULL, bool, decimal, and malicious strings are bound.
$case = ['case' => [
    'when' => [[
        'condition' => ['left' => $field('Status'), 'operator' => '=', 'right' => $literal("S'; DROP TABLE X;--")],
        'then' => $field('Item_Value'),
    ]],
    'else' => $binary(
        ['unary' => ['operator' => '-', 'operand' => $literal(1)]],
        '*',
        $field('Item_Value')
    ),
]];
$caseQuery = $build([
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [
        $sum($case) + ['alias' => 'SignedValue'],
        $binary($literal(1.25), '+', $literal(null)) + ['alias' => 'Nullable'],
        $literal(true) + ['alias' => 'Flag'],
    ],
]);
extendedContains('SUM(CASE WHEN Status = ? THEN Item_Value ELSE ((-?) * Item_Value) END)', $caseQuery['sql'], 'Recursive CASE/unary SQL is incorrect.');
extendedAssert(
    $caseQuery['params'] === ["S'; DROP TABLE X;--", 1, 1.25, null, true],
    'Recursive scalar values were not bound deterministically.'
);
extendedAssert(!str_contains($caseQuery['sql'], 'DROP TABLE'), 'Malicious literal was interpolated into SQL.');

// Window PARTITION BY supports fields and expressions; window ordering remains non-positional.
$windowQuery = $build([
    'action' => 'select', 'source' => ['table' => 'SalesData'],
    'fields' => ['Month', 'Sales', [
        'function' => 'DENSE_RANK',
        'partitionBy' => ['Month', $substring('Bill_Date', 5, 2)],
        'sort' => [['expression' => $substring('Bill_Date', 1, 4), 'direction' => 'DESC']],
        'alias' => 'RowNo',
    ]],
]);
extendedContains(
    'DENSE_RANK() OVER (PARTITION BY Month, SUBSTRING(CONVERT(VARCHAR, Bill_Date), 5, 2) ORDER BY SUBSTRING(CONVERT(VARCHAR, Bill_Date), 1, 4) DESC)',
    $windowQuery['sql'],
    'Window PARTITION BY expression was not generated.'
);
extendedAssert(!str_contains($windowQuery['sql'], 'ORDER BY 1'), 'Window expression generated positional ordering.');

$windowParameterOrder = $build([
    'action' => 'select', 'source' => ['table' => 'SalesData'],
    'fields' => [[
        'function' => 'DENSE_RANK',
        'partitionBy' => [$binary($field('Month'), '+', $literal(1))],
        'sort' => [[
            'expression' => $binary($field('Sales'), '+', $literal(2)),
            'direction' => 'DESC',
        ]],
        'alias' => 'OrderedRank',
    ]],
]);
extendedAssert($windowParameterOrder['params'] === [1, 2], 'Window partition/order parameter order is incorrect.');

$windowInput = $build([
    'action' => 'select', 'source' => ['table' => 'SalesData'],
    'fields' => [[
        'function' => 'LAG',
        'field' => $binary($field('Sales'), '*', $literal(2)),
        'sort' => [['field' => 'Month', 'direction' => 'ASC']],
        'alias' => 'PreviousScaledSales',
    ]],
]);
extendedContains('LAG((Sales * ?), 1) OVER (ORDER BY Month ASC)', $windowInput['sql'], 'Window expression input failed.');
extendedAssert($windowInput['params'] === [2], 'Window expression input parameter was lost.');

$paginationEngine = new ExtendedExpressionEngine();
$paginationBuilder = new SelectBuilder($paginationEngine, new ExtendedExpressionMetadata(), sourcePolicy: new PermissiveQuerySourcePolicy());
$paginationRequest = [
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [$binary($field('Amount'), '+', $literal(1)) + ['alias' => 'Value']],
    'sort' => [[
        'expression' => $binary($field('Amount'), '+', $literal(2)),
        'direction' => 'DESC',
    ]],
    'pagination' => ['page' => 1, 'pageSize' => 10],
];
$validator->validate($paginationRequest);
$paginationBuilt = $paginationBuilder->build($normalizer->normalize($paginationRequest));
extendedAssert($paginationBuilt['params'] === [1, 2], 'Paginated data parameters are incorrect.');
extendedAssert(
    $paginationEngine->executions[0]['params'] === [1],
    'Pagination count received ORDER BY-only expression parameters.'
);

// Expressions work in an existing single CTE and UNION branches.
$cteQuery = $build([
    'action' => 'select', 'source' => ['table' => 'Computed'], 'fields' => ['Value'],
    'with' => ['name' => 'Computed', 'query' => [
        'source' => ['table' => 'Items'],
        'fields' => [$round($binary($field('Amount'), '*', $literal(2)), 0) + ['alias' => 'Value']],
    ]],
]);
extendedContains('WITH Computed AS (', $cteQuery['sql'], 'Single-CTE expression integration failed.');
extendedContains('ROUND((Amount * ?), 0) AS [Value]', $cteQuery['sql'], 'CTE projection expression failed.');
extendedAssert($cteQuery['params'] === [2], 'CTE expression parameter was lost.');

$unionEngine = new ExtendedExpressionEngine();
$unionRepository = new QueryRepository($unionEngine, new ExtendedExpressionMetadata(), sourcePolicy: new PermissiveQuerySourcePolicy());
$unionRequest = [
    'action' => 'unionAll',
    'queries' => [
        ['source' => ['table' => 'Items'], 'fields' => [$binary($field('Amount'), '+', $literal(1)) + ['alias' => 'Value']]],
        ['source' => ['table' => 'Archive'], 'fields' => [$binary($field('Amount'), '+', $literal(2)) + ['alias' => 'Value']]],
    ],
];
$validator->validate($unionRequest);
$unionRepository->select($normalizer->normalize($unionRequest));
$unionExecution = end($unionEngine->executions);
extendedContains('UNION ALL', $unionExecution['sql'], 'UNION expression integration failed.');
extendedAssert($unionExecution['params'] === [1, 2], 'UNION expression parameter order failed.');

// Query 7 remains blocked by its two deliberately out-of-scope public envelopes.
$currentYear = ['function' => 'YEAR', 'field' => ['function' => 'GETDATE']];
$dateText = $binary(
    $binary(
        ['function' => 'CONVERT', 'datatype' => 'VARCHAR',
            'field' => $binary($currentYear, '-', $literal(5))],
        '+',
        $literal('04')
    ),
    '+',
    $literal('01')
);
$query7Boundary = $build([
    'action' => 'select', 'source' => ['table' => 'Items'],
    'fields' => [[
        'function' => 'CONVERT', 'datatype' => 'NUMERIC',
        'field' => $dateText, 'alias' => 'StartDate',
    ]],
]);
extendedContains('CONVERT(NUMERIC, ((CONVERT(VARCHAR, (YEAR(GETDATE()) - ?)) + ?) + ?))', $query7Boundary['sql'], 'Query 7 date-boundary subtree failed.');
extendedAssert($query7Boundary['params'] === [5, '04', '01'], 'Query 7 subtree parameter order failed.');

extendedInvalid($validator, [
    'action' => 'select', 'source' => ['table' => 'RankedData'], 'fields' => ['Month'],
    'with' => [
        ['name' => 'SalesData', 'query' => ['source' => ['table' => 'Items'], 'fields' => ['Month']]],
        ['name' => 'RankedData', 'query' => ['source' => ['table' => 'SalesData'], 'fields' => ['Month']]],
    ],
], 'with.name');
extendedInvalid($validator, [
    'action' => 'select', 'source' => ['table' => 'Items'], 'fields' => ['Bill_Date'],
    'filters' => [[
        'field' => 'Bill_Date', 'operator' => 'BETWEEN',
        'value' => [$field('StartDate'), $field('EndDate')],
    ]],
], 'filters.0.value');

// Security and malformed-recursion rejection.
$base = ['action' => 'select', 'source' => ['table' => 'Items']];
$invalidFields = [
    [['field' => 'Name; DROP TABLE X']],
    [$round($field('Name; DROP TABLE X'), 2)],
    [["function" => "SUM); DROP TABLE X;--", "field" => "Amount"]],
    [$binary($field('Amount'), 'OR 1=1', $literal(1))],
    [$round($field('Amount'), 2) + ['alias' => 'Bad] FROM X;--']],
    [["function" => "CONVERT", "datatype" => "VARCHAR); DROP TABLE X;--", "field" => $field('Amount')]],
    [["function" => "CONVERT", "datatype" => "UNAPPROVEDTYPE", "field" => $field('Amount')]],
    [$round($field('Amount'), 2) + ['unknown' => true]],
    [$binary($field('Amount'), '+', ['literal' => ['unexpected']])],
    [['expression' => ['left' => $field('Amount'), 'operator' => '+', 'right' => 1]]],
];
foreach ($invalidFields as $fields) {
    extendedInvalid($validator, $base + ['fields' => $fields]);
}

$tooDeep = $field('Amount');
for ($index = 0; $index < 35; $index++) {
    $tooDeep = $binary($tooDeep, '+', $literal(1));
}
extendedInvalid($validator, $base + ['fields' => [$tooDeep]]);

$nestedAggregate = $sum($sum($field('Amount')));
extendedInvalid($validator, $base + ['fields' => [$nestedAggregate]]);
$nestedWindow = $round([
    'function' => 'ROW_NUMBER',
    'sort' => [['field' => 'Amount', 'direction' => 'ASC']],
], 0);
extendedInvalid($validator, $base + ['fields' => [$nestedWindow]]);
extendedAssert($engine->executions === [], 'Validation-only expression tests reached query execution.');

echo "Extended expression tests passed.\n";
