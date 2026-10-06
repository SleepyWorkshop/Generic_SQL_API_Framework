<?php

$tests = [
    __DIR__ . '/UniversalApiContractTest.php',
    __DIR__ . '/OrderByWindowRegressionTest.php',
    __DIR__ . '/BackendLogicTest.php',
    __DIR__ . '/ExtendedExpressionTest.php',
    __DIR__ . '/QueryCapabilityTest.php',
    __DIR__ . '/CrudOperationsTest.php',
    __DIR__ . '/SqlControllerTest.php',
    __DIR__ . '/SqlResourceCapabilityTest.php',
    __DIR__ . '/SqlResourceFilteringTest.php',
    __DIR__ . '/SqlResourceDiscoveryTest.php',
    __DIR__ . '/SqlParserGeneratorTest.php',
    __DIR__ . '/SqlParserAssetUrlTest.php',
    __DIR__ . '/QueryExecutionIsolationTest.php',
    __DIR__ . '/LoggerTest.php',
    __DIR__ . '/SecurityAuditLoggingTest.php',
    __DIR__ . '/OperationalLoggingTest.php',
    __DIR__ . '/BackupRecoveryTest.php',
    __DIR__ . '/HealthMonitoringTest.php',
    __DIR__ . '/ProductionSystemHealthTest.php',
    __DIR__ . '/ProductionErrorHandlingTest.php',
    __DIR__ . '/ProductionValidationTest.php',
    __DIR__ . '/DatabaseCredentialEncryptionTest.php',
    __DIR__ . '/DatabaseConfigurationEncryptionTest.php',
    __DIR__ . '/DatabaseAvailabilityLifecycleTest.php',
    __DIR__ . '/RuntimeConfigurationBootstrapTest.php',
    __DIR__ . '/AuthenticationFoundationTest.php',
    __DIR__ . '/FirstTimeSetupTest.php',
    __DIR__ . '/AuthenticationFlowTest.php',
    __DIR__ . '/ApiProtectionTest.php',
    __DIR__ . '/AdminUserManagementTest.php',
    __DIR__ . '/AuthorizationAndApiKeyTest.php',
    __DIR__ . '/FrontendUserMutationAuthorizationTest.php',
    __DIR__ . '/AuthorizationBoundaryTest.php',
    __DIR__ . '/ApiSecurityHardeningTest.php',
    __DIR__ . '/AuthorizationApiCoverageTest.php',
    __DIR__ . '/SecurityHardeningTest.php',
    __DIR__ . '/SecurityTestingTest.php',
    __DIR__ . '/UnifiedAdminConsoleTest.php',
    __DIR__ . '/AdminBasePathTest.php',
    __DIR__ . '/AdminRuntimeManagementTest.php',
    __DIR__ . '/RuntimePerformanceControlsTest.php',
    __DIR__ . '/RuntimeConcurrencyTest.php',
    __DIR__ . '/ProductionRuntimeControlTest.php',
    __DIR__ . '/ProductionApplicationAvailabilityTest.php',
    __DIR__ . '/ProductionServerConfigurationTest.php',
    __DIR__ . '/ProductionHostingTest.php',
    __DIR__ . '/HttpsSecurityTest.php',
    __DIR__ . '/StaticSecurityRemediationTest.php',
    __DIR__ . '/RepositoryDocumentationTest.php'
];

foreach ($tests as $test) {
    echo 'Running ' . basename($test) . PHP_EOL;
    $command = escapeshellarg(PHP_BINARY)
        . (php_ini_loaded_file() === false ? ' -n' : '')
        . ' ' . escapeshellarg($test);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, basename($test) . " failed.\n");
        exit($status);
    }
}

echo "All database-independent backend tests passed.\n";
