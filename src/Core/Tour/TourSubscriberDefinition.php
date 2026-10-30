<?php declare(strict_types=1);

namespace Nova\Versandkosten\Core\Tour;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * Abonnent der Tour-Benachrichtigung („Benachrichtigen, wenn eine Tour in die Nähe fährt“).
 *
 * Quelle „account“ = Schalter an einer Lieferadresse im Kundenkonto, „form“ = Formular für Gäste
 * (Double-Opt-in). Status pending|active. Der DOI-Token liegt nur als Hash in der Tabelle und ist
 * bewusst nicht Teil dieser Definition (nie über die API lesbar); angelegt wird über den Dienst.
 */
class TourSubscriberDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'nova_vk_tour_subscriber';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('email', 'email'))->addFlags(new Required(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('postcode', 'postcode', 20))->addFlags(new Required(), new SearchRanking(SearchRanking::MIDDLE_SEARCH_RANKING)),
            new StringField('country', 'country', 2),
            (new StringField('place', 'place'))->addFlags(new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            new FloatField('latitude', 'latitude'),
            new FloatField('longitude', 'longitude'),
            new FkField('customer_id', 'customerId', CustomerDefinition::class),
            new FkField('customer_address_id', 'customerAddressId', CustomerAddressDefinition::class),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            new StringField('source', 'source', 30),
            new StringField('status', 'status', 20),
            new DateTimeField('confirmed_at', 'confirmedAt'),
            new DateTimeField('last_activity_at', 'lastActivityAt'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id', false),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id', false),
        ]);
    }
}
