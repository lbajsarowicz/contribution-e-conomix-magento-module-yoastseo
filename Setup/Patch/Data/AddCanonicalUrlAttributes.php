<?php

declare(strict_types=1);

namespace MaxServ\YoastSeo\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use MaxServ\YoastSeo\Model\Canonical\CanonicalUrlResolver;

/**
 * Store view scoped canonical URL override for categories and products (empty = default canonical).
 */
class AddCanonicalUrlAttributes implements DataPatchInterface, PatchRevertableInterface
{
    private const GROUP = 'Search Engine Optimization';
    private const NOTE = 'Leave empty for the default canonical. Absolute URL or path, e.g. /some-page';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EavSetupFactory $eavSetupFactory)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach ([Category::ENTITY, Product::ENTITY] as $entityType) {
            $eavSetup->addAttribute($entityType, CanonicalUrlResolver::OVERRIDE_FIELD, [
                'type' => 'varchar',
                'input' => 'text',
                'label' => 'Canonical URL',
                'note' => self::NOTE,
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'required' => false,
                'user_defined' => false,
                'visible' => true,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'group' => self::GROUP,
                'sort_order' => 5,
            ]);
        }

        return $this;
    }

    public function revert(): void
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->removeAttribute(Category::ENTITY, CanonicalUrlResolver::OVERRIDE_FIELD);
        $eavSetup->removeAttribute(Product::ENTITY, CanonicalUrlResolver::OVERRIDE_FIELD);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
