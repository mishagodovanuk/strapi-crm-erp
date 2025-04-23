<?php

namespace Mihod\MarylineService;

use KeyCrm\KeyCrmApi;
use KeyCrm\Client\DefaultHttpClient;
use KeyCrm\Model\Product;

class ProductSyncer
{
    private KeyCrmApi $keyCrmApi;
    private string $strapiBaseUrl;
    private ?string $strapiToken;
    private array $mapping = [];

    public function __construct(
        string $keyCrmBaseUrl,
        string $keyCrmToken,
        string $strapiBaseUrl,
        ?string $strapiToken = null
    ) {
        $httpClient = new DefaultHttpClient($keyCrmBaseUrl, $keyCrmToken);
        $this->keyCrmApi = new KeyCrmApi($httpClient);
        $this->strapiBaseUrl = rtrim($strapiBaseUrl, '/');
        $this->strapiToken = $strapiToken;
    }

    /**
     * Sync configurable products from KeyCRM into Strapi.
     */
    public function syncProducts(): void
    {
        // Fetch configurable products from KeyCRM.
        $keycrmProducts = $this->keyCrmApi->products()->list();

        foreach ($keycrmProducts as $product) {
            // Check if product exists in Strapi by keycrm_id.
            $existingProduct = $this->getStrapiProductByKeycrmId($product->id);
            if ($existingProduct) {
                echo "Product '{$product->name}' already exists in Strapi.\n";
                continue;
            }
            // Create the configurable product in Strapi.
            $newProduct = $this->createStrapiProduct($product);
            if ($newProduct && isset($newProduct['id'])) {
                $this->mapping[$product->id] = $newProduct['id'];
                echo "Created product '{$product->name}' with Strapi ID: " . $newProduct['id'] . "\n";
                // Sync simple variants (articles) using the offers endpoint.
                $this->syncProductArticles($newProduct['id'] - 1, $product->id);
            }
        }
    }

    /**
     * Retrieve a Strapi product by its keycrm_id.
     */
    private function getStrapiProductByKeycrmId(int $keycrmId): ?array
    {
        $url = $this->strapiBaseUrl . '/api/products?populate=*&filters[keycrm_id][$eq]=' . $keycrmId;
        $response = $this->curlGet($url);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    /**
     * Create a configurable product in Strapi based on a KeyCRM product.
     */
    private function createStrapiProduct(Product $product): ?array
    {
        $url = $this->strapiBaseUrl . '/api/products';
        $categoryId = null;
        // If the product has a category, look it up in Strapi by keycrm_id.
        if ($product->category_id) {
            $catUrl = $this->strapiBaseUrl . '/api/categories?populate=*&filters[keycrm_id][$eq]=' . $product->category_id;
            $catResponse = $this->curlGet($catUrl);
            if (isset($catResponse['data']) && count($catResponse['data']) > 0) {
                $categoryId = $catResponse['data'][0]['id'] - 1;
            }
        }

        $data = [
            'data' => [
                'title'       => $product->name,
//                'description' => $product->description,
//                'thumbnail'   => $product->thumbnail_url,
                'keycrm_id'   => $product->id,
//                'sku'         => $product->sku,
//                'min_price'   => $product->min_price,
//                'max_price'   => $product->max_price,
                'currency' => 1, //used to be UA gryvna
                'categories'  => $categoryId ? [$categoryId] : []
            ]
        ];

        $response = $this->curlPost($url, $data);
        return $response['data'] ?? null;
    }

    /**
     * Sync simple variants (articles) for a configurable product.
     * Instead of relying on $product->offers (which is empty per documentation),
     * we call the offers endpoint with a filter on product_id.
     *
     * @param int|string $strapiProductId The ID of the configurable product in Strapi.
     * @param int $keycrmProductId The KeyCRM product ID.
     */
    private function syncProductArticles($strapiProductId, int $keycrmProductId): void
    {
        $query = [
            'filter[product_id]' => $keycrmProductId,
            'limit'      => 50
        ];
        $offers = $this->keyCrmApi->offers()->list($query);

        foreach ($offers as $offer) {
            $existingArticle = $this->getStrapiArticleByKeycrmId($offer['id']);
            if ($existingArticle) {
                echo "Article with keycrm_id {$offer['id']} already exists.\n";
                continue;
            }
            $newArticle = $this->createStrapiArticle($strapiProductId, $offer);
            if ($newArticle && isset($newArticle['id'])) {
                echo "Created article with Strapi ID: " . $newArticle['id'] . "\n";

                $this->createStockForArticle($newArticle['id'], $offer['id']);

            }
        }
    }

    /**
     * Retrieve a Strapi article by its keycrm_id.
     */
    private function getStrapiArticleByKeycrmId(int $keycrmId): ?array
    {
        $url = $this->strapiBaseUrl . '/api/articles?populate=*&filters[keycrm_id][$eq]=' . $keycrmId;
        $response = $this->curlGet($url);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0];
        }
        return null;
    }

    /**
     * Create a new simple variant (article) in Strapi for a configurable product.
     * This method checks for size and color in dedicated collections (creating them if needed)
     * and links the new article to the configurable product.
     */
    private function createStrapiArticle($strapiProductId, $offer): ?array
    {
        $url = $this->strapiBaseUrl . '/api/product-articles';

        // Check or create size from offer properties.
        $sizeId = null;
        if (isset($offer['properties']) && is_array($offer['properties'])) {
            foreach ($offer['properties'] as $prop) {
                if (isset($prop['name']) && mb_strtolower($prop['name']) === mb_strtolower('розмір')) {
                    $sizeId = $this->getOrCreateSize($prop['value']);
                    break;
                }
            }
        }
        // Check or create color from offer properties.
        $colorId = null;
        if (isset($offer['properties']) && is_array($offer['properties'])) {
            foreach ($offer['properties'] as $prop) {
                if (isset($prop['name']) && mb_strtolower($prop['name']) === mb_strtolower('колір')) {
                    $colorId = $this->getOrCreateColor($prop['value']);
                    break;
                }
            }
        }

        $data = [
            'data' => [
                'sku'                 => $offer['sku'] ?: $offer['barcode'],
                'keycrm_id'           => $offer['id'],
                'product'             => $strapiProductId,
                'size'                => $sizeId ? [$sizeId - 1] : null,
                'color'               => $colorId ? [$colorId - 1] : null,
                'price'               => $offer['price']
            ]
        ];

        $response = $this->curlPost($url, $data);
        return $response['data'] ?? null;
    }

    /**
     * Get or create a size in Strapi by name.
     */
    private function getOrCreateSize(string $name): ?int
    {
        $url = $this->strapiBaseUrl . '/api/dictionary-sizes?filters[name][$eq]=' . urlencode($name);
        $response = $this->curlGet($url);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0]['id'];
        }
        // Create new size.
        $url = $this->strapiBaseUrl . '/api/dictionary-sizes';
        $data = [
            'data' => [
                'name' => $name,
            ]
        ];
        $response = $this->curlPost($url, $data);
        return $response['data']['id'] ?? null;
    }

    /**
     * Get or create a color in Strapi by name.
     */
    private function getOrCreateColor(string $name): ?int
    {
        $url = $this->strapiBaseUrl . '/api/dictionary-colors?filters[name][$eq]=' . urlencode($name);
        $response = $this->curlGet($url);
        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0]['id'];
        }
        // Create new color.
        $url = $this->strapiBaseUrl . '/api/dictionary-colors';
        $data = [
            'data' => [
                'name' => $name,
                'key'  => $this->transliterate($name)
            ]
        ];
        $response = $this->curlPost($url, $data);
        return $response['data']['id'] ?? null;
    }

    private function createStockForArticle($articleId, int $keycrmOfferId): void
    {
        // 1. Отримуємо залишки по складам з KeyCRM
        $stocksResponse = $this->keyCrmApi->offers()->getStocks([
            'filter[offers_id]' => $keycrmOfferId,
            'filter[details]' => 'true',
        ]);

        if (!isset($stocksResponse[0]['warehouse'])) {
            return;
        }

        foreach ($stocksResponse[0]['warehouse'] as $warehouse) {
            $storeName = $warehouse['name'];
            $quantity = $warehouse['quantity'] ?? 0;
            $id = $warehouse['id'];

            if ($quantity <= 0) {
                continue;
            }

            $storeId = $this->getOrCreateStore($storeName, $id);

            if (!$storeId) {
                echo "Failed to get or create store: {$storeName}\n";
                continue;
            }

            // 3. Створюємо залишок у Strapi
            $url = $this->strapiBaseUrl . '/api/product-leftovers';
            $data = [
                'data' => [
                    'product_articles' => ($articleId - 1),
                    'quantity' => $quantity,
                    'dictionary_store' => $storeId
                ]
            ];

            $response = $this->curlPost($url, $data);
            if ($response && isset($response['data'])) {
                echo "Created stock for article ID {$articleId} in store '{$storeName}' with quantity {$quantity}.\n";
            }
        }
    }

    private function getOrCreateStore(string $name, $warehouseId): ?int
    {
        $url = $this->strapiBaseUrl . '/api/dictionary-stores?filters[name][$eq]=' . urlencode($name);
        $response = $this->curlGet($url);

        if (isset($response['data']) && count($response['data']) > 0) {
            return $response['data'][0]['id'];
        }

        $url = $this->strapiBaseUrl . '/api/dictionary-stores';
        $data = [
            'data' => [
                'name' => $name,
                'keycrm_id' => (int)$warehouseId
            ]
        ];
        $response = $this->curlPost($url, $data);
        return $response['data']['id'] ?? null;
    }


    /**
     * Helper for GET requests using cURL.
     */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($this->strapiToken) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->strapiToken
            ]);
        }
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('GET request error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    /**
     * Helper for POST requests using cURL.
     */
    private function curlPost(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ['Content-Type: application/json'];
        if ($this->strapiToken) {
            $headers[] = 'Authorization: Bearer ' . $this->strapiToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('POST request error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
    }

    private function transliterate(string $text):? string
    {
        $slug = $text;
        $apostrophes = ["'", "’", "ь", "(", ")"];
        $slug = str_replace($apostrophes, "", $slug);
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
        $transliterated = $transliterator->transliterate($slug);
        $slug = $transliterated;
        $slug = str_replace(' ', '-', $slug);

        return $slug;
    }
}
