<?php declare(strict_types=1);

namespace Swkweb\EventsNetzpMigration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class SwkwebEventsNetzpMigration extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix', ['prefix' => MigrationGuard::STATE_PREFIX . '%']);
        }
    }
}
