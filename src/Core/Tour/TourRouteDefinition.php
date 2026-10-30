<?php declare(strict_types=1);

namespace Nova\Versandkosten\Core\Tour;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SearchRanking;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Strecke (Vorlage für Tourtermine): Name, Start, Ziel, eine oder mehrere Varianten mit Wegpunkten.
 * Nur Administration (Admin-API); nie in der Store-API sichtbar.
 */
class TourRouteDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'nova_vk_tour_route';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getDefaults(): array
    {
        return ['active' => true];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required(), new SearchRanking(SearchRanking::HIGH_SEARCH_RANKING)),
            (new StringField('start_label', 'startLabel'))->addFlags(new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            (new StringField('end_label', 'endLabel'))->addFlags(new SearchRanking(SearchRanking::LOW_SEARCH_RANKING)),
            new BoolField('active', 'active'),
            new LongTextField('note', 'note'),
            (new OneToManyAssociationField('variants', TourVariantDefinition::class, 'route_id'))->addFlags(new CascadeDelete()),
            (new OneToManyAssociationField('dates', TourDateDefinition::class, 'route_id'))->addFlags(new SetNullOnDelete()),
        ]);
    }
}
