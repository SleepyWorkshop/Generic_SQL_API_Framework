<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';

function repositoryDocumentationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$application = require $root . '/config/app.php';
$readme = (string)file_get_contents($root . '/README.md');
$changelog = (string)file_get_contents($root . '/CHANGELOG.md');
$roadmap = (string)file_get_contents($root . '/docs/Roadmap.md');
$aiGuide = (string)file_get_contents($root . '/docs/AI-Development-Guide.md');
$adminExample = json_decode((string)file_get_contents($root . '/config/admin.example.json'), true, 512, JSON_THROW_ON_ERROR);
$databaseStateExample = json_decode((string)file_get_contents($root . '/config/database-state.example.json'), true, 512, JSON_THROW_ON_ERROR);
$applicationRuntimeExample = json_decode((string)file_get_contents($root . '/config/application-runtime-state.example.json'), true, 512, JSON_THROW_ON_ERROR);

repositoryDocumentationAssert(
    ($application['app_name'] ?? null) === 'Generic SQL API Framework'
        && ($application['version'] ?? null) === '2.0.0-dev',
    'Application identity does not represent the unreleased v2 development line.'
);
repositoryDocumentationAssert(
    $adminExample === RuntimeConfiguration::adminDefaults()
        && $databaseStateExample === ['version' => 1, 'available' => false, 'updatedAt' => null],
    'Tracked runtime configuration examples do not match bootstrap defaults.'
);
repositoryDocumentationAssert(
    $applicationRuntimeExample === [
        'version' => 1,
        'generation' => 0,
        'services' => [
            'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
            'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
        ],
    ],
    'Tracked application runtime example does not match bootstrap defaults.'
);
repositoryDocumentationAssert(
    str_contains($changelog, '## [2.0.0] - Unreleased')
        && str_contains($changelog, '## [1.0.0] - Initial release')
        && !preg_match('/## \[1\.[1-9][^]]*\]/', $changelog),
    'Changelog release status is inconsistent.'
);
repositoryDocumentationAssert(
    str_contains($readme, 'last released version is **v1.0.0**')
        && str_contains($readme, '**v2.0.0 development line, which is unreleased**')
        && preg_match('/Phase [1-4](?:\.[0-9]+)?\s+[—-].*implemented/i', $readme) !== 1,
    'README contains an incorrect release status or phase diary.'
);
foreach (['Phase 5', 'Phase 6', 'Phase 7'] as $futurePhase) {
    repositoryDocumentationAssert(str_contains($roadmap, $futurePhase), "Roadmap is missing {$futurePhase}.");
}
repositoryDocumentationAssert(
    str_contains($roadmap, 'Phase 4.13') && str_contains($roadmap, 'are complete'),
    'Roadmap does not record completion of the documentation milestone.'
);
foreach (['request flow', 'X-API-Key', 'queries/system/', 'php -n tests/run.php'] as $guideMarker) {
    repositoryDocumentationAssert(
        str_contains(strtolower($aiGuide), strtolower($guideMarker)),
        "AI development guide is missing {$guideMarker}."
    );
}

foreach (['QueryCapabilityTest.php', 'CrudOperationsTest.php'] as $test) {
    repositoryDocumentationAssert(is_file($root . '/tests/' . $test), "Renamed test is missing: {$test}");
}
foreach ([
    'config/database.php',
    'app/Queries/MetadataQueries.php',
    'app/Controllers/DatabaseController.php',
    'app/Services/DatabaseService.php',
    'app/Repositories/DatabaseRepository.php',
    'queries/system/Databases.sql',
    'docs/SQL-Parser-Backend-Capability-Audit.md',
    'index.php',
] as $obsolete) {
    repositoryDocumentationAssert(!file_exists($root . '/' . $obsolete), "Obsolete file remains: {$obsolete}");
}
foreach (['Tables.sql', 'Columns.sql', 'Views.sql', 'Procedures.sql', 'Schema.sql'] as $metadataQuery) {
    repositoryDocumentationAssert(
        is_file($root . '/queries/system/' . $metadataQuery),
        "Runtime metadata query was removed: {$metadataQuery}"
    );
}

echo "Repository documentation and cleanup tests passed.\n";
