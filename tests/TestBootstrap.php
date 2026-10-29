<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$testBootstrapper = null;
if (is_readable('/opt/share/shopware/tests/TestBootstrapper.php')) {
    // For Docker image: ghcr.io/friendsofshopware/platform-plugin-dev
    $testBootstrapper = require '/opt/share/shopware/tests/TestBootstrapper.php';
} else {
    // Otherwise we need the explicit PROJECT_DIR.
    $projectDir = (string) ($_SERVER['PROJECT_DIR'] ?? '');
    if ($projectDir === '') {
        throw new RuntimeException('Please specify a project directory (PROJECT_DIR) containing Shopware ~6.7.1');
    }

    if (is_readable($projectDir . '/vendor/shopware/core/TestBootstrapper.php')) {
        // Normal vendor setup (e.g. production template)
        require_once $projectDir . '/vendor/shopware/core/TestBootstrapper.php';
    } elseif (is_readable($projectDir . '/platform/src/Core/TestBootstrapper.php')) {
        // Development template
        require_once $projectDir . '/platform/src/Core/TestBootstrapper.php';
    } elseif (is_readable($projectDir . '/src/Core/TestBootstrapper.php')) {
        // Direct platform repository
        require_once $projectDir . '/src/Core/TestBootstrapper.php';
    } else {
        throw new RuntimeException('Cannot find TestBootstrapper in PROJECT_DIR');
    }

    $testBootstrapper = (new TestBootstrapper())
        ->setProjectDir($projectDir)
    ;
}

$projectDir = $testBootstrapper->getProjectDir();
if (is_dir($projectDir . '/custom/plugins/SwkwebEvents') || is_dir('/plugins/SwkwebEvents')) {
    $testBootstrapper->addActivePlugins('SwkwebEvents');
}

$classLoader = $testBootstrapper
    ->setLoadEnvFile(true)
    ->setForceInstallPlugins(true)
    ->addCallingPlugin()
    ->bootstrap()
    ->getClassLoader()
;

// The NetzpEvents6 tables, created outside the test transactions because DDL commits implicitly.
$connection = Shopware\Core\Kernel::getConnection();
foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(__DIR__ . '/Fixture/netzp-schema.sql')))) as $statement) {
    if (!str_starts_with($statement, '--') || str_contains($statement, 'CREATE')) {
        $connection->executeStatement($statement);
    }
}

return $classLoader;
