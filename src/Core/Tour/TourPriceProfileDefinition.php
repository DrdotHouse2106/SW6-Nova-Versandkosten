<?php declare(strict_types=1);

namespace Nova\Versandkosten\Core\Tour;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Preisprofil der eigenen Tour (wer fährt): Preis Kleinteile/Paket/Sperrgut in Cent (brutto),
 * Höchstabweichung in km, Speditionsstufen als JSON-Liste [[bis km, Preis in Cent], …].
 * Der Name ist nur intern (Administration).
 */
class TourPriceProfileDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'nova_vk_tour_price_profile';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getDefaults(): array
    {
        return ['position' => 0, 'freightTiers' => []];
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('name', 'name'))->addFlags(new Required()),
            (new IntField('small_price', 'smallPrice'))->addFlags(new Required()),
            (new FloatField('small_max_km', 'smallMaxKm'))->addFlags(new Required()),
            new JsonField('freight_tiers', 'freightTiers'),
            new IntField('position', 'position'),
            (new OneToManyAssociationField('dates', TourDateDefinition::class, 'price_profile_id'))->addFlags(new SetNullOnDelete()),
        ]);
    }
}
