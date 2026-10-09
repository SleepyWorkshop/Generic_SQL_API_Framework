<?php

require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';

function serverConfigurationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function serverConfigurationRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') serverConfigurationRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

final class ServerConfigurationProcessProbe extends ApiProcessManager
{
    public int $operations = 0;

    public function __construct() {}
    public function status(): array { $this->operations++; return ['running' => false]; }
    public function start(): array { $this->operations++; return []; }
    public function stop(): array { $this->operations++; return []; }
    public function restart(): array { $this->operations++; return []; }
}

/**
 * Run shipped admin.js Configuration functions in Node with a stubbed DOM and
 * Admin API, so assertions inspect what an operator actually receives.
 */
function serverConfigurationNode(string $script, array $argument = []): array
{
    $source = (string)file_get_contents(__DIR__ . '/../admin/assets/admin.js');
    $slice = static function (string $start, string $end) use ($source): string {
        $from = strpos($source, $start);
        $to = $from === false ? false : strpos($source, $end, $from);
        serverConfigurationAssert($from !== false && $to !== false, "admin.js no longer contains {$start}.");
        return substr($source, $from, $to - $from);
    };
    $program = <<<'JS'
const input = JSON.parse(process.argv[1]);
const rendered = { sections: [], target: { innerHTML: "", querySelector: () => ({ addEventListener() {} }), querySelectorAll: () => [] } };
let activeConfigTab = "server";
const title = { textContent: "" };
const content = { className: "", innerHTML: "", querySelectorAll: () => [] };
const document = { querySelector: () => rendered.target };
const loading = () => {};
const row = (response) => response.data[0];
const call = async () => ({ data: [input.settings] });
const setButtonBusy = () => () => {};
const notify = () => {};
const securitySection = () => rendered.sections.push("security");
const runtimeSection = () => rendered.sections.push("runtime");
const advancedSection = () => rendered.sections.push("advanced");
JS;
    $program .= $slice('const escapeHtml =', 'const row =')
        . $slice('function configurationTabs(', 'const productionWebServers =')
        . $slice('function developmentServerMarkup(', 'function securitySection(')
        . '(async () => { const result = await (async () => { ' . $script . ' })();'
        . ' process.stdout.write(JSON.stringify({ result, activeConfigTab, tabs: content.innerHTML,'
        . ' sections: rendered.sections, section: rendered.target.innerHTML })); })()'
        . '.catch((error) => { process.stderr.write(String(error && error.message)); process.exit(1); });';
    $process = proc_open(
        ['node', '-e', $program, json_encode($argument, JSON_THROW_ON_ERROR)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    serverConfigurationAssert(is_resource($process), 'Node.js is required to render the Admin Configuration page.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    serverConfigurationAssert(proc_close($process) === 0, "Admin Configuration rendering failed: {$error}");
    return json_decode((string)$output, true, 512, JSON_THROW_ON_ERROR);
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/generic-server-configuration-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$logs = $directory . '/logs';
$oldEnvironment = getenv('GENERIC_APP_ENV');
$oldConfigurationDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logs, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv('GENERIC_APP_ENV=development');
    RuntimeConfiguration::ensure();

    $configuration = new AdminConfigurationRepository();
    $adminPath = RuntimeConfiguration::path(RuntimeConfiguration::ADMIN_FILE);
    $apiProbe = new ServerConfigurationProcessProbe();
    $service = new AdminService(
        $configuration,
        $directory . '/database.json',
        static function (): void {},
        $apiProbe,
        null,
        new SqlParserProcessManager($configuration, null, null, $directory . '/sqlparser-process.json', $root),
        null,
        new DatabaseAvailabilityManager(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE)),
        new Logger($logs),
        null,
        new ApplicationRuntimeManager(RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE))
    );

    // 1. Development continues to expose the launcher's port settings.
    $development = $service->settings();
    serverConfigurationAssert(
        $development['hostingMode'] === 'development'
            && !array_key_exists('hosting', $development)
            && $development['server'] === RuntimeConfiguration::adminDefaults()['server'],
        'Development settings no longer expose the launcher server configuration.'
    );
    $developmentPage = serverConfigurationNode('await configurationView("server");', ['settings' => $development]);
    $developmentMarkup = $developmentPage['section'];
    serverConfigurationAssert(
        $developmentPage['activeConfigTab'] === 'server' && $developmentPage['sections'] === []
            && preg_match_all('/data-tab="([a-z]+)"/', $developmentPage['tabs'], $tabs) === 4
            && $tabs[1] === ['server', 'security', 'runtime', 'advanced'],
        'Development Configuration no longer offers the Server tab first.'
    );
    serverConfigurationAssert(
        serverConfigurationNode('return configurationTabs("development");')['result'] === ['server', 'security', 'runtime', 'advanced']
            && serverConfigurationNode('return resolveConfigurationTab("features", "development");')['result'] === 'server'
            && serverConfigurationNode('return resolveConfigurationTab("database", "development");')['result'] === 'server',
        'Development Configuration tabs changed.'
    );
    foreach ([
        'name="apiPortMinimum"', 'name="apiPortMaximum"', 'name="parserPortMinimum"',
        'name="parserPortMaximum"', 'name="adminPort"', 'name="bindAddress"',
        'API Port Minimum', 'Parser Port Maximum', 'Admin Port', 'Bind Address',
        '>Save Server Configuration</button>', '>Restart API</button>', '>Restart SQL Parser</button>',
    ] as $marker) {
        serverConfigurationAssert(str_contains($developmentMarkup, $marker), "Development Server tab is missing {$marker}.");
    }
    serverConfigurationAssert(
        str_contains($developmentMarkup, 'value="8000"') && str_contains($developmentMarkup, 'value="8090"'),
        'Development Server tab does not render the stored port values.'
    );

    // 2. Development server configuration still saves and reports restarts.
    $changed = [...$development['server'], 'apiPortMinimum' => 8010, 'apiPortMaximum' => 8020];
    $saved = $service->saveServer($changed);
    serverConfigurationAssert(
        $saved['server'] === $changed
            && $saved['apiRestartRequired'] === true
            && $saved['parserRestartRequired'] === false
            && $saved['adminRestartRequired'] === false
            && $configuration->load()['server'] === $changed,
        'Development server configuration was not saved with the correct restart requirements.'
    );

    // 3 and 4. Production exposes no listener ports and claims only web-server ownership.
    putenv('GENERIC_APP_ENV=production');
    $production = $service->settings();
    serverConfigurationAssert(
        $production['hostingMode'] === 'production'
            && $production['server'] === null
            && !array_key_exists('hosting', $production)
            && !preg_match('/PortMinimum|PortMaximum|adminPort|bindAddress/', json_encode($production, JSON_THROW_ON_ERROR)),
        'Production settings expose development listener configuration.'
    );
    $detector = new RuntimeDetector();
    serverConfigurationAssert(
        $detector->productionWebServer('Microsoft-IIS/10.0', 'Linux') === 'iis'
            && $detector->productionWebServer('nginx/1.26.0', 'Windows') === 'nginx'
            && $detector->productionWebServer('', 'Windows') === 'iis'
            && $detector->productionWebServer('', 'Linux') === 'nginx'
            && $detector->productionWebServer('', 'Darwin') === 'web-server',
        'Production web server ownership was not detected from the hosting environment.'
    );

    // Production has no Server tab; requests for it, including the default
    // tab, the legacy "features" alias, and the removed Database tab, fall
    // back to Security without touching the null server configuration.
    // Database connections are managed only on the Databases page.
    serverConfigurationAssert(
        serverConfigurationNode('return configurationTabs("production");')['result'] === ['security', 'runtime', 'advanced'],
        'Production Configuration still offers the Server tab.'
    );
    foreach (['await configurationView("server");', 'await configurationView();', 'await configurationView("features");', 'await configurationView("unknown");', 'await configurationView("database");'] as $request) {
        $page = serverConfigurationNode($request, ['settings' => $production]);
        preg_match_all('/data-tab="([a-z]+)"/', $page['tabs'], $tabs);
        serverConfigurationAssert(
            $page['activeConfigTab'] === 'security' && $page['sections'] === ['security'] && $page['section'] === ''
                && $tabs[1] === ['security', 'runtime', 'advanced']
                && !preg_match('/Server|apiPortMinimum|Save Server Configuration|Restart API|Restart SQL Parser/', $page['tabs'] . $page['section']),
            "Production Configuration rendered Server content for {$request}."
        );
    }
    foreach (['security', 'runtime', 'advanced'] as $tab) {
        $page = serverConfigurationNode("await configurationView(\"{$tab}\");", ['settings' => $production]);
        serverConfigurationAssert(
            $page['activeConfigTab'] === $tab && $page['sections'] === [$tab],
            "Production Configuration did not render the {$tab} tab."
        );
    }
    $adminSource = (string)file_get_contents($root . '/admin/assets/admin.js');
    serverConfigurationAssert(
        !str_contains($adminSource, 'productionServerMarkup') && !preg_match('/settings\.hosting(?!Mode)/', $adminSource)
            && substr_count($adminSource, 'serverSection(target, settings.server)') === 1
            && strpos($adminSource, 'activeConfigTab = resolveConfigurationTab(tab, settings.hostingMode)')
                < strpos($adminSource, 'serverSection(target, settings.server)'),
        'Production can still reach the development Server section.'
    );

    // 5. Production save is rejected and changes nothing.
    $storedBefore = (string)file_get_contents($adminPath);
    $operationsBefore = $apiProbe->operations;
    try {
        $service->saveServer([...$changed, 'apiPortMinimum' => 9000, 'apiPortMaximum' => 9010, 'adminPort' => 9443]);
        serverConfigurationAssert(false, 'Production server save reported success.');
    } catch (ApiRequestException $exception) {
        serverConfigurationAssert(
            $exception->getStatusCode() === 409
                && $exception->getErrorCode() === 'SERVER_CONFIGURATION_DEPLOYMENT_MANAGED'
                && $exception->getDetails() === [],
            'Production server save was not rejected as deployment-managed.'
        );
    }
    serverConfigurationAssert(
        (string)file_get_contents($adminPath) === $storedBefore && $apiProbe->operations === $operationsBefore,
        'Rejected production server save changed configuration or touched a process manager.'
    );
    $auditLog = implode("\n", array_map(
        static fn (string $path): string => (string)file_get_contents($path),
        glob($logs . '/*.log') ?: []
    ));
    serverConfigurationAssert(
        str_contains($auditLog, '"configurationCategory":"server"') && str_contains($auditLog, '"reason":"deployment_managed"'),
        'Rejected production server save was not security-audited.'
    );

    // Development behavior is restored as soon as the environment changes back.
    putenv('GENERIC_APP_ENV=development');
    serverConfigurationAssert(
        $service->settings()['server'] === $changed,
        'Development server configuration did not survive production mode.'
    );

    // 6, 7, and 8. Every IIS boundary declares production mode on its own FastCGI registration.
    $boundaries = [
        'api' => 'deployment/iis/api.web.config.example',
        'sqlparser' => 'deployment/iis/sqlparser.web.config.example',
        'admin' => 'deployment/iis/admin.web.config.example',
    ];
    foreach ($boundaries as $boundary => $relative) {
        $xml = (string)file_get_contents($root . '/' . $relative);
        $arguments = "arguments='-d generic_sql_api.boundary={$boundary}'";
        serverConfigurationAssert(
            str_contains($xml, "scriptProcessor=\"C:\\PHP\\php-cgi.exe|-d generic_sql_api.boundary={$boundary}\""),
            "IIS {$boundary} handler does not use its dedicated FastCGI registration."
        );
        serverConfigurationAssert(
            str_contains($xml, "{$arguments}].environmentVariables.[name='GENERIC_APP_ENV',value='production']")
                && str_contains($xml, "{$arguments}].environmentVariables.[name='PHPRC',value='C:\\PHP']"),
            "IIS {$boundary} template does not establish production mode explicitly."
        );
        serverConfigurationAssert(
            !str_contains((string)preg_replace('/<!--.*?-->/s', '', $xml), 'GENERIC_APP_ENV'),
            "IIS {$boundary} template sets the environment outside its FastCGI registration."
        );
        $comments = [];
        preg_match_all('/<!--(.*?)-->/s', $xml, $comments);
        foreach ($comments[1] as $comment) {
            serverConfigurationAssert(!str_contains($comment, '--'), "IIS {$boundary} template contains an invalid XML comment.");
        }
        $mentionsAdminFlag = str_contains($xml, "[name='GENERIC_ADMIN_ENABLED',value='1']");
        serverConfigurationAssert(
            $boundary === 'admin' ? $mentionsAdminFlag : !$mentionsAdminFlag,
            "IIS {$boundary} template assigns GENERIC_ADMIN_ENABLED to the wrong boundary."
        );
    }
    $adminXml = (string)file_get_contents($root . '/' . $boundaries['admin']);
    serverConfigurationAssert(
        str_contains($adminXml, 'allowUnlisted="false"') && str_contains($adminXml, '<add ipAddress="127.0.0.1" allowed="true" />')
            && str_contains($adminXml, 'Route Admin pages'),
        'IIS Admin template no longer preserves its loopback boundary and routing.'
    );
    $report = (new ProductionValidator($root))->report();
    serverConfigurationAssert(
        ($report['staticValidation']['templates'] ?? null) === ProductionValidator::VALIDATED,
        'Production validator rejected the IIS templates.'
    );

    echo "Production server configuration tests passed.\n";
} finally {
    $oldEnvironment === false
        ? putenv('GENERIC_APP_ENV')
        : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    $oldConfigurationDirectory === false
        ? putenv('GENERIC_RUNTIME_CONFIG_DIR')
        : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldConfigurationDirectory);
    serverConfigurationRemove($directory);
}
