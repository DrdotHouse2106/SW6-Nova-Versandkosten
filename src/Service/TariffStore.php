<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service;

use Nova\Versandkosten\Calculator\Tariff\TariffException;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Speichert die importierte Tarifdatei global in der Datenbank (system_config, nicht im Dateisystem
 * des Plugins). Die Datei wird beim Import geprüft und in der geprüften JSON-Fassung abgelegt.
 */
class TariffStore implements ResetInterface
{
    public const KEY_DATA = 'NovaVersandkosten.tariffs.data';
    public const KEY_META = 'NovaVersandkosten.tariffs.meta';

    private ?TariffSet $loaded = null;

    private bool $tried = false;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->loaded = null;
        $this->tried = false;
    }

    public function get(): ?TariffSet
    {
        if ($this->tried) {
            return $this->loaded;
        }
        $this->tried = true;

        $data = $this->config->get(self::KEY_DATA);
        if (!\is_array($data) || $data === []) {
            return null;
        }

        try {
            return $this->loaded = TariffSet::fromArray($data);
        } catch (TariffException $e) {
            $this->logger->error('Nova Versandkosten: gespeicherte Tarifdatei ungültig', ['errors' => $e->errors]);

            return null;
        }
    }

    public function save(TariffSet $set, string $fileName): void
    {
        $this->config->set(self::KEY_DATA, $set->data);
        $this->config->set(self::KEY_META, [
            'name' => $set->name,
            'fileName' => $fileName,
            'importedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'products' => \count($set->products),
            'options' => $set->options(),
        ]);
        $this->reset();
    }

    /** @return array<string, mixed>|null */
    public function meta(): ?array
    {
        $meta = $this->config->get(self::KEY_META);

        return \is_array($meta) ? $meta : null;
    }
}
