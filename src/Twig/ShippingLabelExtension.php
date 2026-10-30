<?php declare(strict_types=1);

namespace Nova\Versandkosten\Twig;

use Nova\Versandkosten\Calculator\ProductLabel;
use Nova\Versandkosten\Service\ProductClassifier;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-Funktion für Produktexporte (z. B. Google Shopping):
 *
 *   <g:shipping_label>{{ nova_versand_label(product, context) }}</g:shipping_label>
 *
 * Ergebnis: paket, sperrgut, spedition oder abholung (siehe README „Google“).
 */
class ShippingLabelExtension extends AbstractExtension
{
    public function __construct(private readonly ProductClassifier $classifier)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('nova_versand_label', $this->label(...))];
    }

    public function label(mixed $product, mixed $context = null): string
    {
        if (!$product instanceof ProductEntity) {
            return '';
        }
        $salesChannelId = $context instanceof SalesChannelContext ? $context->getSalesChannelId() : null;
        $customFields = $product->getTranslation('customFields');
        [$category] = $this->classifier->classify(
            \is_array($customFields) ? $customFields : ($product->getCustomFields() ?? []),
            $product->getPropertyIds() ?? [],
            $salesChannelId
        );

        $l = ProductClassifier::mmToCm($product->getLength());
        $w = ProductClassifier::mmToCm($product->getWidth());
        $h = ProductClassifier::mmToCm($product->getHeight());

        return ProductLabel::label($category, $l !== null && $w !== null && $h !== null ? [$l, $w, $h] : null);
    }
}
