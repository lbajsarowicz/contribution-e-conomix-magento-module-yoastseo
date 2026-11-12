<?php

declare(strict_types=1);

namespace MaxServ\YoastSeo\Model\Canonical;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Final canonical URL of a storefront page, on top of the legacy canonical built by the YoastSeo block.
 *
 * Always: no canonical on the 404 page, the home page points to the base URL, and the
 * yoast_canonical_url field of a CMS page, category or product overrides everything.
 *
 * With yoastseo/general/canonical_from_entity_url enabled, the canonical is the URL of the entity
 * itself (product without category path, category plus page number, CMS page), so it follows the
 * configured URL suffix instead of always getting a trailing slash. The legacy canonical stays the
 * default to keep existing shops unchanged.
 */
class CanonicalUrlResolver
{
    public const OVERRIDE_FIELD = 'yoast_canonical_url';
    public const XML_PATH_ENTITY_URL_MODE = 'yoastseo/general/canonical_from_entity_url';
    private const XML_PATH_HOME_PAGE = 'web/default/cms_home_page';

    public function __construct(
        private readonly Http $request,
        private readonly Registry $registry,
        private readonly Page $cmsPage,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(?string $legacyUrl): ?string
    {
        switch ($this->request->getFullActionName()) {
            case 'cms_noroute_index':
                return null;
            case 'catalog_product_view':
                $product = $this->registry->registry('current_product');
                if (!$product instanceof Product) {
                    return $legacyUrl;
                }

                return $this->override($product) ?? (
                    $this->isEntityUrlMode()
                        ? $this->withoutQuery(
                            (string) $product->getUrlModel()->getUrl($product, ['_ignore_category' => true])
                        )
                        : $legacyUrl
                );
            case 'catalog_category_view':
                $category = $this->registry->registry('current_category');
                if (!$category instanceof Category) {
                    return $legacyUrl;
                }

                return $this->override($category) ?? (
                    $this->isEntityUrlMode()
                        ? $this->withoutQuery((string) $category->getUrl()) . $this->pageQuery()
                        : $legacyUrl
                );
            case 'cms_index_index':
                return $this->override($this->cmsPage) ?? $this->baseUrl();
            case 'cms_page_view':
                if (!$this->cmsPage->getId()) {
                    return $legacyUrl;
                }

                $identifier = (string) $this->cmsPage->getIdentifier();
                if ($override = $this->override($this->cmsPage)) {
                    return $override;
                }
                if ($this->isHomePage($identifier)) {
                    return $this->baseUrl();
                }

                return $this->isEntityUrlMode() ? $this->baseUrl() . ltrim($identifier, '/') : $legacyUrl;
            default:
                return $legacyUrl;
        }
    }

    private function override(AbstractModel $entity): ?string
    {
        $value = trim((string) $entity->getData(self::OVERRIDE_FIELD));
        if ($value === '') {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $value)) {
            $value = $this->baseUrl() . ltrim($value, '/');
        }

        if (!preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    /**
     * The home page is also reachable by its own identifier (e.g. /home), both point to the base URL.
     */
    private function isHomePage(string $identifier): bool
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_PATH_HOME_PAGE, ScopeInterface::SCOPE_STORE);

        return $identifier !== '' && explode('|', $configured, 2)[0] === $identifier;
    }

    private function isEntityUrlMode(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENTITY_URL_MODE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Paginated category pages stay self-referencing, every other parameter (filter, sorting, tracking) is dropped.
     */
    private function pageQuery(): string
    {
        $page = (string) $this->request->getParam('p', '');

        return ctype_digit($page) && (int) $page > 1 ? '?p=' . (int) $page : '';
    }

    private function withoutQuery(string $url): string
    {
        return explode('?', $url, 2)[0];
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->storeManager->getStore()->getBaseUrl(), '/') . '/';
    }
}
