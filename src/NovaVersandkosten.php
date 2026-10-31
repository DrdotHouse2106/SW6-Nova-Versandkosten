<?php declare(strict_types=1);

namespace Nova\Versandkosten;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Installer\CustomFieldInstaller;
use Nova\Versandkosten\Installer\MailTemplateInstaller;
use Nova\Versandkosten\Installer\ShippingMethodInstaller;
use Nova\Versandkosten\Migration\Migration1793450000NovaVkTour;
use Nova\Versandkosten\Migration\Migration1793460000NovaVkTourPriceProfile;
use Nova\Versandkosten\Service\MethodMap;
use Nova\Versandkosten\Service\Tour\PostcodeDirectory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Nova Versandkosten – Versandkosten aus Einkaufstarifen, Paketbildung und Kanalregeln berechnen;
 * ab 1.2.0 zusätzlich eigene Touren (Strecken, Tourtermine, Tour-Benachrichtigung), ab 1.2.1 Preisprofile je Fahrer.
 *
 * Die Tabellen der Touren legt die Migration an (Migration1793450000NovaVkTour). Beim Installieren,
 * Aktualisieren und Aktivieren lädt das Plugin die mitgelieferten PLZ-Koordinaten (GeoNames, CC BY 4.0),
 * wenn sich die Datei geändert hat. Deinstallieren ohne „Daten behalten“ entfernt die Tabellen wieder.
 */
class NovaVersandkosten extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->setup($installContext->getContext());
        $this->importPostcodes($installContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->setup($updateContext->getContext());
        $this->importPostcodes($updateContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->importPostcodes($activateContext);
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

        // Eigene Touren: Tabellen entfernen (Reihenfolge wegen Fremdschlüsseln: Termine vor Preisprofilen)
        foreach ([...Migration1793450000NovaVkTour::TABLES, ...Migration1793460000NovaVkTourPriceProfile::TABLES] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    /**
     * PLZ-Koordinaten laden. Shopware führt die Migrationen erst NACH install()/update()/activate() aus –
     * deshalb hier zuerst die (noch offenen) Migrationen des Plugins ausführen, damit die Tabelle existiert.
     */
    private function importPostcodes(InstallContext $context): void
    {
        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }
        try {
            if ($context->isAutoMigrate()) {
                $context->getMigrationCollection()->migrateInPlace();
            }
            if ($connection->createSchemaManager()->tablesExist(['nova_vk_postcode'])) {
                (new PostcodeDirectory($connection))->import();
            }
        } catch (\Throwable) {
            // PLZ fehlen nur für die Tour; beim nächsten Update/Aktivieren erneut versuchen
        }
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
