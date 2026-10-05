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
// Planned work stays explicitly planned under the versioned roadmap structure.
foreach ([
    'v2.1 — Security Verification & Operational Hardening',
    'v3.0 — Multi-Database Support',
    'v3.1 — Developer Experience & API Integration',
] as $plannedMilestone) {
    repositoryDocumentationAssert(
        preg_match('/^# ' . preg_quote($plannedMilestone, '/') . '\R+Status: Planned$/mu', $roadmap) === 1,
        "Roadmap does not list {$plannedMilestone} as planned."
    );
}

// The completed production hardening phases are recorded with their scope.
repositoryDocumentationAssert(
    preg_match('/^## Production Hardening\R+Status: Completed$/m', $roadmap) === 1,
    'Roadmap does not record the completed production hardening milestone.'
);
foreach ([
    1 => 'Production configuration and IIS ownership',
    2 => 'API and SQL Parser application availability',
    3 => 'Application database availability',
    4 => 'Production System Health',
    5 => 'Admin Console cleanup',
] as $phase => $scope) {
    repositoryDocumentationAssert(
        str_contains($roadmap, "| Phase {$phase} | {$scope} | Completed |")
            && preg_match('/^- \*\*Phase ' . $phase . ' — [^*]+:\*\* \S/m', $roadmap) === 1,
        "Roadmap does not describe completed Phase {$phase} ({$scope})."
    );
}
repositoryDocumentationAssert(
    str_contains($roadmap, 'production documentation and validation milestone')
        && str_contains($roadmap, '](Windows-IIS-Deployment.md)')
        && str_contains($roadmap, 'is also complete')
        && is_file($root . '/docs/Windows-IIS-Deployment.md'),
    'Roadmap does not record completion of the production documentation milestone.'
);
foreach (['Filesystem health', 'Session health'] as $removedCheck) {
    repositoryDocumentationAssert(
        !str_contains($roadmap, $removedCheck),
        "Roadmap still lists the removed System Health check: {$removedCheck}."
    );
}

// Relative Markdown links and their heading anchors must resolve.
$markdownSlug = static fn (string $heading): string => str_replace(' ', '-',
    (string)preg_replace('/[^\p{L}\p{N} _-]/u', '', strtolower(trim($heading))));
$markdownAnchors = static function (string $path) use ($markdownSlug): array {
    preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', (string)file_get_contents($path), $headings);
    return array_map($markdownSlug, $headings[1]);
};
foreach (array_merge([$root . '/README.md', $root . '/CHANGELOG.md'], glob($root . '/docs/*.md') ?: []) as $document) {
    preg_match_all('/\]\(([^)\s]*)\)/', (string)file_get_contents($document), $links);
    foreach ($links[1] as $link) {
        if ($link === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $link) === 1) continue;
        [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $targetPath = $target === '' ? $document : dirname($document) . '/' . rawurldecode($target);
        repositoryDocumentationAssert(file_exists($targetPath), basename($document) . " links to a missing file: {$link}");
        if ($anchor !== null && $anchor !== '' && str_ends_with($targetPath, '.md')) {
            repositoryDocumentationAssert(
                in_array($anchor, $markdownAnchors($targetPath), true),
                basename($document) . " links to a missing heading: {$link}"
            );
        }
    }
}
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
