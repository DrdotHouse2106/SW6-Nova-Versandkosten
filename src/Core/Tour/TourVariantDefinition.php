<?php declare(strict_types=1);

namespace Nova\Versandkosten\Core\Tour;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Variante einer Strecke (z. B. „über A-Stadt“). Wegpunkte als JSON-Liste in Fahrtreihenfolge:
 * [{label, postcode, place, lat, lon}, …].
 */
class TourVariantDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'nova_vk_tour_variant';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getDefaults(): array
    {
        return ['position' => 0, 'waypoints' => []];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('route_id', 'routeId', TourRouteDefinition::class))->addFlags(new Required()),
            (new StringField('name', 'name'))->addFlags(new Required()),
            new IntField('position', 'position'),
            new JsonField('waypoints', 'waypoints'),
            new ManyToOneAssociationField('route', 'route_id', TourRouteDefinition::class, 'id', false),
            (new OneToManyAssociationField('dates', TourDateDefinition::class, 'variant_id'))->addFlags(new SetNullOnDelete()),
        ]);
    }
}
