<?php

declare(strict_types=1);

namespace MaxServ\YoastSeo\Test\Unit\Model\Canonical;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MaxServ\YoastSeo\Model\Canonical\CanonicalUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalUrlResolverTest extends TestCase
{
    private const BASE_URL = 'https://shop.test/';
    private const LEGACY_URL = 'https://shop.test/whatever/?foo=1';

    private Registry $registry;
    private Page $cmsPage;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        $this->cmsPage = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
    }

    /*
     * Bug fixes, active in every shop.
     */

    public function testHomepagePointsToBaseUrlInsteadOfAppendingItsIdentifier(): void
    {
        $this->cmsPage->setData(['page_id' => 2, 'identifier' => 'home']);

        $this->assertSame(self::BASE_URL, $this->resolver('cms_index_index')->resolve('https://shop.test/home/'));
    }

    #[DataProvider('homePageConfigValues')]
    public function testHomepageCmsPageOpenedByItsOwnUrlPointsToBaseUrl(string $configValue): void
    {
        $this->cmsPage->setData(['page_id' => 2, 'identifier' => 'home']);

        $this->assertSame(
            self::BASE_URL,
            $this->resolver('cms_page_view', [], false, $configValue)->resolve('https://shop.test/home/')
        );
    }

    public static function homePageConfigValues(): array
    {
        return [
            'identifier' => ['home'],
            'identifier with page id' => ['home|2'],
        ];
    }

    public function testNotFoundPageHasNoCanonical(): void
    {
        $this->cmsPage->setData(['page_id' => 1, 'identifier' => 'no-route']);

        $this->assertNull($this->resolver('cms_noroute_index')->resolve('https://shop.test/no-route/'));
    }

    public function testLegacyModeKeepsTheLegacyCanonical(): void
    {
        $this->registry->register('current_product', $this->product('https://shop.test/test'));

        $this->assertSame(self::LEGACY_URL, $this->resolver('catalog_product_view')->resolve(self::LEGACY_URL));
    }

    /*
     * Override field, active in every shop (empty by default).
     */

    public function testProductOverrideWins(): void
    {
        $this->registry->register(
            'current_product',
            $this->product('https://shop.test/test', 'https://shop.test/other-product')
        );

        $this->assertSame(
            'https://shop.test/other-product',
            $this->resolver('catalog_product_view')->resolve(self::LEGACY_URL)
        );
    }

    public function testCategoryOverrideWithRelativePathGetsBaseUrl(): void
    {
        $this->registry->register('current_category', $this->category('https://shop.test/test-cat', '/aluschienen'));

        $this->assertSame(
            'https://shop.test/aluschienen',
            $this->resolver('catalog_category_view', ['p' => '3'], true)->resolve(self::LEGACY_URL)
        );
    }

    public function testCmsPageOverrideWins(): void
    {
        $this->cmsPage->setData([
            'page_id' => 5,
            'identifier' => 'enable-cookies',
            CanonicalUrlResolver::OVERRIDE_FIELD => ' https://www.example.com/datenschutz ',
        ]);

        $this->assertSame(
            'https://www.example.com/datenschutz',
            $this->resolver('cms_page_view')->resolve(self::LEGACY_URL)
        );
    }

    #[DataProvider('invalidOverrides')]
    public function testInvalidOverrideIsIgnored(string $override): void
    {
        $this->cmsPage->setData([
            'page_id' => 5,
            'identifier' => 'enable-cookies',
            CanonicalUrlResolver::OVERRIDE_FIELD => $override,
        ]);

        $this->assertSame(
            'https://shop.test/enable-cookies',
            $this->resolver('cms_page_view', [], true)->resolve(self::LEGACY_URL)
        );
    }

    public static function invalidOverrides(): array
    {
        return [
            'empty' => ['   '],
            'javascript scheme' => ['javascript:alert(1)'],
            'other scheme' => ['ftp://shop.test/file'],
            'attribute breakout' => ['https://shop.test/a" onload="x'],
        ];
    }

    /*
     * Entity URL mode (yoastseo/general/canonical_from_entity_url).
     */

    public function testProductUsesUrlWithoutCategoryPathAndWithoutParameters(): void
    {
        $this->registry->register('current_product', $this->product('https://shop.test/test?___store=de'));

        $this->assertSame(
            'https://shop.test/test',
            $this->resolver('catalog_product_view', [], true)->resolve(self::LEGACY_URL)
        );
    }

    public function testProductUrlKeepsItsConfiguredSuffix(): void
    {
        $this->registry->register('current_product', $this->product('https://shop.test/test/'));

        $this->assertSame(
            'https://shop.test/test/',
            $this->resolver('catalog_product_view', [], true)->resolve(self::LEGACY_URL)
        );
    }

    public function testCategoryFirstPageHasNoParameters(): void
    {
        $this->registry->register('current_category', $this->category('https://shop.test/test-cat'));

        $this->assertSame(
            'https://shop.test/test-cat',
            $this->resolver('catalog_category_view', ['p' => '1', 'product_list_order' => 'name'], true)
                ->resolve(self::LEGACY_URL)
        );
    }

    public function testCategoryPaginationIsSelfReferencingButOtherParametersAreDropped(): void
    {
        $this->registry->register('current_category', $this->category('https://shop.test/test-cat'));

        $this->assertSame(
            'https://shop.test/test-cat?p=2',
            $this->resolver('catalog_category_view', ['p' => '2', 'product_list_order' => 'name'], true)
                ->resolve(self::LEGACY_URL)
        );
    }

    public function testInvalidPaginationValueIsIgnored(): void
    {
        $this->registry->register('current_category', $this->category('https://shop.test/test-cat'));

        $this->assertSame(
            'https://shop.test/test-cat',
            $this->resolver('catalog_category_view', ['p' => 'abc'], true)->resolve(self::LEGACY_URL)
        );
    }

    public function testCmsPageUsesIdentifierWithoutTrailingSlash(): void
    {
        $this->cmsPage->setData(['page_id' => 5, 'identifier' => 'enable-cookies']);

        $this->assertSame(
            'https://shop.test/enable-cookies',
            $this->resolver('cms_page_view', [], true)->resolve('https://shop.test/enable-cookies/')
        );
    }

    public function testUnknownPageKeepsLegacyUrl(): void
    {
        $this->assertSame(
            self::LEGACY_URL,
            $this->resolver('contact_index_index', [], true)->resolve(self::LEGACY_URL)
        );
    }

    public function testProductPageWithoutRegisteredProductKeepsLegacyUrl(): void
    {
        $this->assertSame(
            self::LEGACY_URL,
            $this->resolver('catalog_product_view', [], true)->resolve(self::LEGACY_URL)
        );
    }

    private function resolver(
        string $fullActionName,
        array $params = [],
        bool $entityUrlMode = false,
        string $homePage = 'home'
    ): CanonicalUrlResolver {
        $request = $this->createStub(Http::class);
        $request->method('getFullActionName')->willReturn($fullActionName);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn(self::BASE_URL);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['web/default/cms_home_page', 'store', null, $homePage],
        ]);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            [CanonicalUrlResolver::XML_PATH_ENTITY_URL_MODE, 'store', null, $entityUrlMode],
        ]);

        return new CanonicalUrlResolver($request, $this->registry, $this->cmsPage, $storeManager, $scopeConfig);
    }

    private function product(string $url, ?string $override = null): Product
    {
        $product = $this->createStub(Product::class);
        $urlModel = $this->createStub(ProductUrl::class);
        $urlModel->method('getUrl')->willReturnCallback(
            static fn ($passedProduct, array $params = []) => $passedProduct === $product
                && ($params['_ignore_category'] ?? false) === true ? $url : 'https://shop.test/wrong-call'
        );
        $product->method('getUrlModel')->willReturn($urlModel);
        $product->method('getData')->willReturnMap([[CanonicalUrlResolver::OVERRIDE_FIELD, null, $override]]);

        return $product;
    }

    private function category(string $url, ?string $override = null): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getUrl')->willReturn($url);
        $category->method('getData')->willReturnMap([[CanonicalUrlResolver::OVERRIDE_FIELD, null, $override]]);

        return $category;
    }
}
