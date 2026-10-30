<?php declare(strict_types=1);

namespace Nova\Versandkosten;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Installer\CustomFieldInstaller;
use Nova\Versandkosten\Installer\MailTemplateInstaller;
use Nova\Versandkosten\Installer\ShippingMethodInstaller;
use Nova\Versandkosten\Service\MethodMap;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Nova Versandkosten – Versandkosten aus Einkaufstarifen, Paketbildung und Kanalregeln berechnen.
 */
class NovaVersandkosten extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->setup($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->setup($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        $sets = $this->container?->get('custom_field_set.repository');
        if (!$connection instanceof Connection) {
            return;
        }

        if ($sets instanceof EntityRepository) {
            (new CustomFieldInstaller($sets, $connection))->uninstall($uninstallContext->getContext());
        }
        (new MailTemplateInstaller($connection))->uninstall();

        // Versandarten können an Bestellungen hängen und werden deshalb nur deaktiviert, nicht gelöscht.
        $connection->executeStatement(
            'UPDATE shipping_method SET active = 0 WHERE technical_name LIKE :p',
            ['p' => MethodMap::PREFIX . '%']
        );
        $connection->executeStatement('DELETE FROM `system_config` WHERE `configuration_key` LIKE \'NovaVersandkosten.%\'');
    }

    private function setup(Context $context): void
    {
        $connection = $this->container?->get(Connection::class);
        $methods = $this->container?->get('shipping_method.repository');
        $sets = $this->container?->get('custom_field_set.repository');
        if (!$connection instanceof Connection || !$methods instanceof EntityRepository || !$sets instanceof EntityRepository) {
            return;
        }

        (new ShippingMethodInstaller($methods, $connection))->install($context);
        (new CustomFieldInstaller($sets, $connection))->install($context);
        (new MailTemplateInstaller($connection))->install();
    }
}
