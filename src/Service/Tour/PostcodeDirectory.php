<?php declare(strict_types=1);

namespace Nova\Versandkosten\Service\Tour;

use Doctrine\DBAL\Connection;
use Nova\Versandkosten\Calculator\Tour\GeoPoint;
use Nova\Versandkosten\Calculator\Tour\PostcodeFile;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Postleitzahl → Koordinate (PLZ-Mittelpunkt) aus der mitgelieferten GeoNames-Datei
 * (src/Resources/geo/postcodes-de.tsv.gz, CC BY 4.0, siehe NOTICE). Kein externer Dienst.
 *
 * Die Datei wird beim Installieren/Update in die Tabelle nova_vk_postcode geladen, wenn sie sich
 * geändert hat (Prüfsumme in system_config).
 */
class PostcodeDirectory implements ResetInterface
{
    public const FILE = PostcodeFile::PATH;
    public const CHECKSUM_KEY = 'NovaVersandkosten.postcodes.checksum';

    /** @var array<string, array{lat: float, lon: float, place: string}|null> */
    private array $memo = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    public static function normalize(string $postcode): string
    {
        return PostcodeFile::normalize($postcode);
    }

    /**
     * Mittelpunkt einer PLZ (bei mehreren Orten der Durchschnitt) – null, wenn unbekannt.
     *
     * @return array{lat: float, lon: float, place: string}|null
     */
    public function lookup(string $country, string $postcode): ?array
    {
        $country = strtoupper(trim($country));
        $postcode = self::normalize($postcode);
        if ($postcode === '' || \strlen($country) !== 2) {
            return null;
        }
        $key = $country . '|' . $postcode;
        if (\array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $row = $this->connection->fetchAssociative(
            'SELECT AVG(latitude) AS lat, AVG(longitude) AS lon, MIN(NULLIF(place, \'\')) AS place, COUNT(*) AS n
               FROM nova_vk_postcode WHERE country = :c AND postcode = :p',
            ['c' => $country, 'p' => $postcode]
        );
        if ($row === false || (int) $row['n'] === 0 || !is_numeric($row['lat']) || !is_numeric($row['lon'])) {
            return $this->memo[$key] = null;
        }

        return $this->memo[$key] = ['lat' => (float) $row['lat'], 'lon' => (float) $row['lon'], 'place' => (string) ($row['place'] ?? '')];
    }

    public function point(string $country, string $postcode): ?GeoPoint
    {
        $hit = $this->lookup($country, $postcode);

        return $hit === null ? null : new GeoPoint($hit['lat'], $hit['lon']);
    }

    /**
     * Suche für die Wegpunkt-Eingabe (Administration): PLZ (auch Anfang) oder Ortsname.
     * Bei einem Ort mit mehreren PLZ steht zuerst der Mittelpunkt aller PLZ dieses Orts.
     *
     * @return list<array{label: string, postcode: string, place: string, state: string, lat: float, lon: float}>
     */
    public function search(string $query, string $country = 'DE', int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 80) {
            return [];
        }
        $country = strtoupper($country);
        $out = [];

        if (preg_match('/^\d{2,5}$/', $query) === 1) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT postcode, MIN(NULLIF(place, \'\')) AS place, MIN(state) AS state, AVG(latitude) AS lat, AVG(longitude) AS lon
                   FROM nova_vk_postcode WHERE country = :c AND postcode LIKE :p
                  GROUP BY postcode ORDER BY postcode LIMIT ' . max(1, min(50, $limit)),
                ['c' => $country, 'p' => $query . '%']
            );
            foreach ($rows as $row) {
                $out[] = self::entry((string) $row['postcode'], (string) ($row['place'] ?? ''), (string) ($row['state'] ?? ''), (float) $row['lat'], (float) $row['lon']);
            }

            return $out;
        }

        $like = addcslashes($query, '%_\\');
        $places = $this->connection->fetchAllAssociative(
            'SELECT place, state, COUNT(*) AS n, MIN(postcode) AS first, MAX(postcode) AS last, AVG(latitude) AS lat, AVG(longitude) AS lon
               FROM nova_vk_postcode WHERE country = :c AND place LIKE :q AND place <> \'\'
              GROUP BY place, state ORDER BY (place = :exact) DESC, (place LIKE :starts) DESC, place LIMIT 10',
            ['c' => $country, 'q' => '%' . $like . '%', 'exact' => $query, 'starts' => $like . '%']
        );
        foreach ($places as $row) {
            $n = (int) $row['n'];
            $postcode = $n > 1 ? $row['first'] . '–' . $row['last'] : (string) $row['first'];
            $entry = self::entry($n > 1 ? '' : (string) $row['first'], (string) $row['place'], (string) ($row['state'] ?? ''), (float) $row['lat'], (float) $row['lon']);
            if ($n > 1) {
                $entry['label'] = $row['place'] . ' (Mitte, PLZ ' . $postcode . ')';
            }
            $out[] = $entry;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{label: string, postcode: string, place: string, state: string, lat: float, lon: float}
     */
    private static function entry(string $postcode, string $place, string $state, float $lat, float $lon): array
    {
        return [
            'label' => trim($postcode . ' ' . $place),
            'postcode' => $postcode,
            'place' => $place,
            'state' => $state,
            'lat' => round($lat, 4),
            'lon' => round($lon, 4),
        ];
    }

    /**
     * Lädt die mitgelieferte Datei, wenn sich ihre Prüfsumme geändert hat (oder die Tabelle leer ist).
     *
     * @return int Anzahl geladener Zeilen (0 = unverändert)
     */
    public function import(string $file = self::FILE, bool $force = false): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $checksum = (string) md5_file($file);
        $stored = $this->connection->fetchOne(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, \'$._value\')) FROM system_config WHERE configuration_key = :k AND sales_channel_id IS NULL',
            ['k' => self::CHECKSUM_KEY]
        );
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nova_vk_postcode');
        if (!$force && $stored === $checksum && $count > 0) {
            return 0;
        }

        $rows = PostcodeFile::read($file);
        if ($rows === []) {
            return 0;
        }

        $this->connection->transactional(function (Connection $connection) use ($rows): void {
            $countries = array_values(array_unique(array_column($rows, 0)));
            foreach ($countries as $country) {
                $connection->executeStatement('DELETE FROM nova_vk_postcode WHERE country = :c', ['c' => $country]);
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), '(?, ?, ?, ?, ?, ?)'));
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, ...$row);
                }
                $connection->executeStatement('INSERT IGNORE INTO nova_vk_postcode (country, postcode, place, state, latitude, longitude) VALUES ' . $placeholders, $params);
            }
        });

        // Prüfsumme merken (system_config hat für „alle Verkaufskanäle“ keinen eindeutigen Schlüssel)
        $this->connection->executeStatement('DELETE FROM system_config WHERE configuration_key = :k AND sales_channel_id IS NULL', ['k' => self::CHECKSUM_KEY]);
        $this->connection->executeStatement(
            'INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) VALUES (:id, :k, :v, NULL, NOW(3))',
            ['id' => random_bytes(16), 'k' => self::CHECKSUM_KEY, 'v' => json_encode(['_value' => $checksum])]
        );
        $this->reset();

        return \count($rows);
    }
}
