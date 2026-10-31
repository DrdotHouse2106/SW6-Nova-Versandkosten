<?php declare(strict_types=1);

namespace Nova\Versandkosten\Core\Tour;

use Nova\Versandkosten\Calculator\Tour\TourDate;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Tourtermin: Strecke (Vorlage) oder eigene Wegpunkte (einmalige Fahrt), Datum, Bestellschluss
 * (letzter Bestelltag), „mit Hänger“, Status open|fixed|closed, – sobald festgelegt – die Variante und
 * das Preisprofil (wer fährt; leer = Standardprofil).
 */
class TourDateDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'nova_vk_tour_date';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getDefaults(): array
    {
        return ['status' => TourDate::STATUS_OPEN, 'trailer' => false];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            new FkField('route_id', 'routeId', TourRouteDefinition::class),
            new FkField('variant_id', 'variantId', TourVariantDefinition::class),
            new FkField('price_profile_id', 'priceProfileId', TourPriceProfileDefinition::class),
            (new StringField('name', 'name'))->addFlags(new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new DateField('tour_date', 'tourDate'))->addFlags(new Required()),
            (new DateField('order_deadline', 'orderDeadline'))->addFlags(new Required()),
            new BoolField('trailer', 'trailer'),
            (new StringField('status', 'status', 20))->addFlags(new Required()),
            new JsonField('waypoints', 'waypoints'),
            new LongTextField('note', 'note'),
            new DateTimeField('notified_at', 'notifiedAt'),
            new ManyToOneAssociationField('route', 'route_id', TourRouteDefinition::class, 'id', false),
            new ManyToOneAssociationField('variant', 'variant_id', TourVariantDefinition::class, 'id', false),
            new ManyToOneAssociationField('priceProfile', 'price_profile_id', TourPriceProfileDefinition::class, 'id', false),
        ]);
    }
}
