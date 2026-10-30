<?php declare(strict_types=1);

namespace Nova\Versandkosten\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * E-Mail-Vorlagen des Plugins (de/en):
 * - „Nova Versandkosten: Versandkosten nachberechnet“ (Typ TYPE): geht an den Kunden, wenn der Shop bei
 *   einer Bestellung „Versandkosten auf Anfrage“ den Preis einträgt.
 * - „Tour-Benachrichtigung bestätigen“ (TYPE_TOUR_DOI) und „Tour in Ihrer Nähe“ (TYPE_TOUR_NOTIFY).
 *
 * Jede Vorlage wird nur angelegt, wenn ihr Typ fehlt – Änderungen des Inhabers (Einstellungen → E-Mail-Vorlagen)
 * bleiben bei Updates erhalten. Variablen: order, salesChannel, novaVersandkosten.shippingCosts,
 * .total, .currencyIsoCode, .paymentUrl, .guest, .cancelDays.
 */
final class MailTemplateInstaller
{
    public const TYPE = 'nova_versandkosten.request_priced';
    /** Tour-Benachrichtigung: Double-Opt-in (Variablen novaTour.confirmUrl, .postcode, .days) */
    public const TYPE_TOUR_DOI = 'nova_versandkosten.tour_notify_doi';
    /** Tour-Benachrichtigung: Tour in der Nähe (novaTour.date, .dateShort, .area, .deadline, .trailer, .shopUrl, .renewUrl, .unsubscribeUrl) */
    public const TYPE_TOUR_NOTIFY = 'nova_versandkosten.tour_notify';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function install(): void
    {
        foreach ($this->definitions() as $type => $def) {
            $this->installType($type, $def);
        }
    }

    /**
     * Alle Vorlagen des Plugins: Typ → Namen, Betreff, Beschreibung und Inhalte (de/en).
     *
     * @return array<string, array{entities: array<string, string>, name: array{0: string, 1: string}, subject: array{0: string, 1: string}, description: array{0: string, 1: string}, html: array{0: string, 1: string}, plain: array{0: string, 1: string}}>
     */
    private function definitions(): array
    {
        return [
            self::TYPE => [
                'entities' => ['order' => 'order', 'salesChannel' => 'sales_channel'],
                'name' => ['Nova Versandkosten: Versandkosten nachberechnet', 'Nova Shipping Costs: shipping costs calculated'],
                'subject' => ['Versandkosten für Ihre Bestellung {{ order.orderNumber }}', 'Shipping costs for your order {{ order.orderNumber }}'],
                'description' => ['An den Kunden, wenn die Versandkosten einer Bestellung „auf Anfrage“ eingetragen wurden (Nova Versandkosten)', 'Sent to the customer when the shipping costs of an “on request” order were entered (Nova Shipping Costs)'],
                'html' => [self::HTML_DE, self::HTML_EN],
                'plain' => [self::PLAIN_DE, self::PLAIN_EN],
            ],
            self::TYPE_TOUR_DOI => [
                'entities' => ['salesChannel' => 'sales_channel'],
                'name' => ['Nova Versandkosten: Tour-Benachrichtigung bestätigen', 'Nova Shipping Costs: confirm tour notification'],
                'subject' => ['Bitte bestätigen: Benachrichtigung über unsere Touren', 'Please confirm: notification about our delivery tours'],
                'description' => ['Double-Opt-in für die Tour-Benachrichtigung (Formular für Gäste, Nova Versandkosten)', 'Double opt-in for the tour notification (guest form, Nova Shipping Costs)'],
                'html' => [TourMailTemplates::DOI_HTML_DE, TourMailTemplates::DOI_HTML_EN],
                'plain' => [TourMailTemplates::DOI_PLAIN_DE, TourMailTemplates::DOI_PLAIN_EN],
            ],
            self::TYPE_TOUR_NOTIFY => [
                'entities' => ['salesChannel' => 'sales_channel'],
                'name' => ['Nova Versandkosten: Tour in Ihrer Nähe', 'Nova Shipping Costs: tour near you'],
                'subject' => ['Wir liefern am {{ novaTour.date }} in Ihrer Nähe', 'We deliver near you on {{ novaTour.date }}'],
                'description' => ['Benachrichtigung an Interessenten im Korridor eines Tourtermins (Knopf in der Administration, Nova Versandkosten)', 'Notification to subscribers along a tour date (button in the administration, Nova Shipping Costs)'],
                'html' => [TourMailTemplates::NOTIFY_HTML_DE, TourMailTemplates::NOTIFY_HTML_EN],
                'plain' => [TourMailTemplates::NOTIFY_PLAIN_DE, TourMailTemplates::NOTIFY_PLAIN_EN],
            ],
        ];
    }

    /**
     * @param array{entities: array<string, string>, name: array{0: string, 1: string}, subject: array{0: string, 1: string}, description: array{0: string, 1: string}, html: array{0: string, 1: string}, plain: array{0: string, 1: string}} $def
     */
    private function installType(string $type, array $def): void
    {
        $exists = $this->connection->fetchOne('SELECT 1 FROM mail_template_type WHERE technical_name = :t', ['t' => $type]);
        if ($exists !== false) {
            return;
        }

        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $typeId = Uuid::randomBytes();
        $templateId = Uuid::randomBytes();

        $this->connection->insert('mail_template_type', [
            'id' => $typeId,
            'technical_name' => $type,
            'available_entities' => json_encode($def['entities']),
            'created_at' => $now,
        ]);
        $this->connection->insert('mail_template', [
            'id' => $templateId,
            'mail_template_type_id' => $typeId,
            'system_default' => 1,
            'created_at' => $now,
        ]);

        foreach ($this->languages() as $languageId => $german) {
            $i = $german ? 0 : 1;
            $this->connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $typeId,
                'language_id' => $languageId,
                'name' => $def['name'][$i],
                'created_at' => $now,
            ]);
            $this->connection->insert('mail_template_translation', [
                'mail_template_id' => $templateId,
                'language_id' => $languageId,
                'sender_name' => '{{ salesChannel.translated.name }}',
                'subject' => $def['subject'][$i],
                'description' => $def['description'][$i],
                'content_html' => $def['html'][$i],
                'content_plain' => $def['plain'][$i],
                'created_at' => $now,
            ]);
        }
    }

    public function uninstall(): void
    {
        foreach (array_keys($this->definitions()) as $type) {
            $typeId = $this->connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :t', ['t' => $type]);
            if ($typeId === false) {
                continue;
            }
            $this->connection->executeStatement('DELETE FROM mail_template WHERE mail_template_type_id = :id', ['id' => $typeId]);
            $this->connection->executeStatement('DELETE FROM mail_template_type WHERE id = :id', ['id' => $typeId]);
        }
    }

    /**
     * Systemsprache plus alle Sprachen mit Locale; true = deutsch.
     *
     * @return array<string, bool> Sprach-ID (binär) → deutsch?
     */
    private function languages(): array
    {
        $out = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT language.id, locale.code FROM language INNER JOIN locale ON locale.id = language.locale_id'
        );
        foreach ($rows as $row) {
            $out[(string) $row['id']] = str_starts_with((string) $row['code'], 'de');
        }
        $system = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        if (!isset($out[$system])) {
            $out[$system] = false;
        }

        return $out;
    }

    private const HTML_DE = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Hallo {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},</p>
    <p>vielen Dank für Ihre Bestellung {{ order.orderNumber }}. Wir haben die Versandkosten berechnet:</p>
    <table cellpadding="4" style="font-family:arial; font-size:12px;">
        <tr><td>Versandkosten</td><td style="text-align:right;">{{ novaVersandkosten.shippingCosts|currency(novaVersandkosten.currencyIsoCode) }}</td></tr>
        <tr><td><strong>Gesamtbetrag</strong></td><td style="text-align:right;"><strong>{{ novaVersandkosten.total|currency(novaVersandkosten.currencyIsoCode) }}</strong></td></tr>
    </table>
    <p><strong>Ihre Bestellung wird verbindlich, sobald Sie den Gesamtbetrag bezahlen.</strong> Hier können Sie bezahlen bzw. die Zahlungsart wählen:<br>
        <a href="{{ novaVersandkosten.paymentUrl }}">{{ novaVersandkosten.paymentUrl }}</a></p>
    {% if novaVersandkosten.guest %}<p>Zur Anmeldung brauchen Sie Ihre E-Mail-Adresse und die Postleitzahl Ihrer Rechnungsadresse.</p>{% endif %}
    {% if novaVersandkosten.cancelDays > 0 %}<p>Möchten Sie zu diesem Preis nicht bestellen, müssen Sie nichts tun: Ohne Zahlung wird die Bestellung nach {{ novaVersandkosten.cancelDays }} Tagen automatisch storniert.</p>{% endif %}
    <p>Viele Grüße<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    private const PLAIN_DE = <<<'TWIG'
Hallo {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},

vielen Dank für Ihre Bestellung {{ order.orderNumber }}. Wir haben die Versandkosten berechnet:

Versandkosten: {{ novaVersandkosten.shippingCosts|currency(novaVersandkosten.currencyIsoCode) }}
Gesamtbetrag: {{ novaVersandkosten.total|currency(novaVersandkosten.currencyIsoCode) }}

Ihre Bestellung wird verbindlich, sobald Sie den Gesamtbetrag bezahlen. Hier können Sie bezahlen bzw. die Zahlungsart wählen:
{{ novaVersandkosten.paymentUrl }}
{% if novaVersandkosten.guest %}
Zur Anmeldung brauchen Sie Ihre E-Mail-Adresse und die Postleitzahl Ihrer Rechnungsadresse.
{% endif %}{% if novaVersandkosten.cancelDays > 0 %}
Möchten Sie zu diesem Preis nicht bestellen, müssen Sie nichts tun: Ohne Zahlung wird die Bestellung nach {{ novaVersandkosten.cancelDays }} Tagen automatisch storniert.
{% endif %}
Viele Grüße
{{ salesChannel.translated.name }}
TWIG;

    private const HTML_EN = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Hello {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},</p>
    <p>thank you for your order {{ order.orderNumber }}. We have calculated the shipping costs:</p>
    <table cellpadding="4" style="font-family:arial; font-size:12px;">
        <tr><td>Shipping costs</td><td style="text-align:right;">{{ novaVersandkosten.shippingCosts|currency(novaVersandkosten.currencyIsoCode) }}</td></tr>
        <tr><td><strong>Total</strong></td><td style="text-align:right;"><strong>{{ novaVersandkosten.total|currency(novaVersandkosten.currencyIsoCode) }}</strong></td></tr>
    </table>
    <p><strong>Your order becomes binding once you pay the total amount.</strong> You can pay or choose the payment method here:<br>
        <a href="{{ novaVersandkosten.paymentUrl }}">{{ novaVersandkosten.paymentUrl }}</a></p>
    {% if novaVersandkosten.guest %}<p>To sign in you need your e-mail address and the postcode of your billing address.</p>{% endif %}
    {% if novaVersandkosten.cancelDays > 0 %}<p>If you do not want to order at this price, you do not need to do anything: without payment the order is cancelled automatically after {{ novaVersandkosten.cancelDays }} days.</p>{% endif %}
    <p>Kind regards<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    private const PLAIN_EN = <<<'TWIG'
Hello {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }},

thank you for your order {{ order.orderNumber }}. We have calculated the shipping costs:

Shipping costs: {{ novaVersandkosten.shippingCosts|currency(novaVersandkosten.currencyIsoCode) }}
Total: {{ novaVersandkosten.total|currency(novaVersandkosten.currencyIsoCode) }}

Your order becomes binding once you pay the total amount. You can pay or choose the payment method here:
{{ novaVersandkosten.paymentUrl }}
{% if novaVersandkosten.guest %}
To sign in you need your e-mail address and the postcode of your billing address.
{% endif %}{% if novaVersandkosten.cancelDays > 0 %}
If you do not want to order at this price, you do not need to do anything: without payment the order is cancelled automatically after {{ novaVersandkosten.cancelDays }} days.
{% endif %}
Kind regards
{{ salesChannel.translated.name }}
TWIG;
}
