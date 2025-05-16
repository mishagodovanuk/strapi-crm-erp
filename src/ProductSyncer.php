<?php

namespace Mihod\MarylineService;

use KeyCrm\KeyCrmApi;
use KeyCrm\Client\DefaultHttpClient;
use KeyCrm\Model\Product;
use KeyCrm\Exception\KeyCrmException;

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
        $counter = 0;

        foreach ($keycrmProducts as $product) {
            $counter++;

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

            // every 10 items, pause for 2 seconds
            if ($counter % 10 === 0) {
                sleep(2);
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
//                'info' => "## Опис товару і догляд\n" .
//                    "Товар з пальтової тканини, дрібноворсистої з принтом в ялинку. " .
//                    "Пальто вільного крою (оверсайз), опущений плечовий шов. " .
//                    "Спереду наявні два ряди гудзиків.\n\n" .
//                    "### Склад:\n" .
//                    "- 20% вовна\n" .
//                    "- 40% віскоза\n" .
//                    "- 40% поліестер\n" .
//                    "- Матеріал підкладки: 100% віскоза.\n\n" .
//                    "### Догляд:\n" .
//                    "- Тільки суха чистка (хімчистка). ![allow.svg](http://localhost:1337/uploads/allow_eb92266009.svg)\n" .
//                    "- Заборонена сушка в барабані. ![denied.svg](http://localhost:1337/uploads/denied_50d90c4330.svg)\n" .
//                    "- Вироби з вмістом вовни мають схильність до кошлатання. " .
//                    "Температурний режим від + 5 до + 15 градусів\n\n" .
//                    "## Параметри виробу і моделі\n" .
//                    "### Параметри виробу\n" .
//                    "- XS-S: Ширина (півобхват) пальто в області грудей 49 см, довжина виробу від коміру - 120 см, довжина рукава - 60 см.\n" .
//                    "- M-L: Ширина (півобхват) пальто в області грудей 52 см, довжина виробу від коміру - 120 см, довжина рукава - 60 см.\n\n" .
//                    "### Параметри моделі\n" .
//                    "- Ріст: 170 см\n" .
//                    "- Груди: 84 см\n" .
//                    "- Талія: 60 см\n" .
//                    "- Стегна: 90 см\n" .
//                    "- На моделі розмір: XS-S",
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

        $offers = [];
        $maxRetries = 10;
        $delayUs = 1000_000; // 1 second in microseconds
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $offers = $this->keyCrmApi->offers()->list($query);
                break; // Success, exit retry loop
            } catch (KeyCrmException $e) {
                $attempt++;
                if (strpos($e->getMessage(), '429') !== false) {
                    echo "429 Too Many Requests for product ID {$keycrmProductId}, retry {$attempt}/{$maxRetries} after " . ($delayUs / 1e6) . "s\n";
                    usleep($delayUs);
                    continue;
                }
                echo "Error fetching offers for product ID {$keycrmProductId}: {$e->getMessage()}, retry {$attempt}/{$maxRetries} after " . ($delayUs / 1e6) . "s\n";
                usleep($delayUs);
                continue;
            }
        }

        if ($attempt >= $maxRetries) {
            echo "Failed to fetch offers for product ID {$keycrmProductId} after {$maxRetries} retries.\n";
            return;
        }

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
                'key' => mb_strtolower($this->transliterate($name))
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
                'key'  => $this->transliterate($name),
                'hex' => $this->getColorFromMap($name)
            ]
        ];
        $response = $this->curlPost($url, $data);
        return $response['data']['id'] ?? null;
    }

    private function createStockForArticle($articleId, int $keycrmOfferId): void
    {
        $stocksResponse = $this->getStocksWithRetry($keycrmOfferId);

        if (empty($stocksResponse) || !isset($stocksResponse[0]['warehouse'])) {
            return;
        }

        foreach ($stocksResponse[0]['warehouse'] as $warehouse) {
            $storeName = $warehouse['name'];
            $quantity  = $warehouse['quantity'] ?? 0;
            $id        = $warehouse['id'];

//            if ($quantity == 0) {
//                continue;
//            }

            $storeId = $this->getOrCreateStore($storeName, $id);
            if (!$storeId) {
                echo "Failed to get or create store: {$storeName}\n";
                continue;
            }

            $url = $this->strapiBaseUrl . '/api/product-leftovers';
            $data = [
                'data' => [
                    'product_article' => ($articleId - 1),
                    'quantity'         => $quantity,
                    'dictionary_store' => ($storeId - 1),
                ]
            ];

            $response = $this->curlPost($url, $data);
            if ($response && isset($response['data'])) {
                echo "Created stock for article ID {$articleId} in store '{$storeName}' with quantity {$quantity}.\n";
            }
        }
    }

    /**
     * Пробуємо getStocks до $maxRetries разів з короткою затримкою на 429,
     * після чого повертаємо пустий масив (і ніяких виключень).
     */
    private function getStocksWithRetry(int $keycrmOfferId): array
    {
        $query      = [
            'filter[offers_id]' => $keycrmOfferId,
            'filter[details]'   => 'true',
        ];
        $maxRetries = 10;
        $delayUs    = 1000_000;
        $attempt    = 0;

        while ($attempt < $maxRetries) {
            try {
                return $this->keyCrmApi->offers()->getStocks($query);
            } catch (KeyCrmException $e) {
                $attempt++;
                if (strpos($e->getMessage(), '429') !== false) {
                    echo "429 Too Many Attempts on offer {$keycrmOfferId}, retry {$attempt}/{$maxRetries} after " . ($delayUs / 1e6) . "s\n";
                    usleep($delayUs);
                    continue;
                }
                echo "Request error on offer {$keycrmOfferId}, retry {$attempt}/{$maxRetries} after " . ($delayUs / 1e6) . "s\n";
                usleep($delayUs);
                continue;
            }
        }

        echo "stocks for offer {$keycrmOfferId} after {$maxRetries} retries.\n";
        return [];
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
    private function curlGet(string $url, int $attempt = 0): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($this->strapiToken) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->strapiToken
            ]);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \Exception('GET request error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 500 && $status < 600) {
            echo "Server error {$status} on GET {$url}, skipping.\n";
            return [];
        }
        if ($status === 429 && $attempt < 3) {
            usleep(200_000);
            return $this->curlGet($url, $attempt + 1);
        }
        return json_decode($response, true) ?: [];
    }

    private function curlPost(string $url, array $data, int $attempt = 0): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers = ['Content-Type: application/json'];
        if ($this->strapiToken) {
            $headers[] = 'Authorization: Bearer ' . $this->strapiToken;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            curl_close($ch);
            throw new \Exception('POST request error: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 500 && $status < 600) {
            echo "Server error {$status} on POST {$url}, skipping.\n";
            return null;
        }
        if ($status === 429 && $attempt < 3) {
            usleep(200_000);
            return $this->curlPost($url, $data, $attempt + 1);
        }
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

    private function getColorFromMap($colorName) {
        $colorMap = [
            'чорний'                    => '#000000',
            'білий'                     => '#FFFFFF',
            'червоний'                  => '#FF0000',
            'синій'                     => '#0000FF',
            'коричневий'                => '#8B4513',
            'світло молочний'           => '#FFF8E7',
            'молочний'                  => '#FFF9E3',
            'світло-молочний'           => '#FFF8E7',
            'світло молочний (бежевий ведмедик)' => '#EFE3D4',
            'світло молочний (сірий ведмедик)'  => '#F5F3F0',
            'світло молоч'              => '#FFF8E7',
            'бежевий'                   => '#F5F5DC',
            'світло бежевий'            => '#F5DEB3',
            'бежевий меланж'            => '#D1BCA8',
            'ялинка бежева'             => '#F5F5DC',
            'крем'                      => '#FFFDD0',
            'кремовий'                  => '#FFFDD0',
            'беж'                       => '#F5F5DC',
            'сірий'                     => '#808080',
            'світло сірий'              => '#D3D3D3',
            'світло сірий 02'           => '#DADADA',
            'графіт'                    => '#2F4F4F',
            'кашка сіра'                => '#BEBEBE',
            'сірий меланж'              => '#A9A9A9',
            'сірий (шарк)'              => '#778899',
            'сірий рубчик'              => '#8A8A8A',
            'сірий мікімаус'            => '#808080',
            'сірий коса'                => '#909090',
            'sirij-melanz'              => '#A9A9A9',
            'sirij'                     => '#808080',
            'сніжний меланж'            => '#F5F5F5',
            'шоколад рубчик'            => '#4B2E18',
            'темний шоколад рубчик'     => '#3E2723',
            'білий рубчик'              => '#FFFFFF',
            'чорний рубчик'             => '#000000',
            'білий коса'                => '#FFFFFF',
            'чорний коса'               => '#000000',
            'шоколад'                   => '#5D3A1A',
            'мокко'                     => '#967969',
            'темний шоколад'            => '#3E2723',
            'шоколадний'                => '#5D3A1A',
            'світлий кемел'             => '#D2B48C',
            'кемел'                     => '#C19A6B',
            'блакитний'                 => '#5BC0EB',
            'блакитний темний'          => '#005A9C',
            'небесний (петля)'          => '#87CEEB',
            'блакитний мікімаус'        => '#5BC0EB',
            'сірий блакитний'           => '#6699CC',
            'бірюза'                    => '#30D5C8',
            'оливка'                    => '#708238',
            'хакі'                      => '#4B5320',
            'квіточки зелений'          => '#228B22',
            'зелений'                   => '#008000',
            'лаванда'                   => '#E6E6FA',
            'електрик'                  => '#0050FF',
            'бордо'                     => '#800020',
            'бургунді'                  => '#811A21',
            'малиновий'                 => '#D52D5D',
            'малина'                    => '#C2185B',
            'малинова'                  => '#C2185B',
            'рожевий'                   => '#FFC0CB',
            'квіточки рожевий'          => '#FFA6C9',
            'пудра'                     => '#F8D1D1',
            'маршмелоу'                 => '#FFEFFB',
            'гірчиця'                   => '#FFDB58',
            'жовтий'                    => '#FFFF00',
            'темний кемел'              => '#A68064',
            'темно сірий'               => '#696969',
            'оlivka'                    => '#808000',
            'чорний в квітку'           => '#000000',
            'чорний тіар'               => '#060606',
            'чорний щільний'            => '#131313',
            'сердечка коричневі'        => '#8B4513',
            'сердечка'                  => '#FFB6C1',
            'сердечка сірий'            => '#C0C0C0',
            'сердечка рожеві'           => '#FFC0CB',
            'середечка коричневі'       => '#8B4513',
            'горошок сіро білий'        => '#EAEAEA',
            'горошок попелястий'        => '#C0C0C0',
            'гусяча лапка чорно-біла'   => '#000000', // контрастний принт
            'леопард'                   => '#D2A679',
            'кактус'                    => '#228B22',
            'ялинка сіра'               => '#BEBEBE',
            'ялинка пісочна'            => '#DEB887',
            'ялинка бежевий'            => '#F5F5DC',
            'elektrik'                  => '#0050FF',
            'Dzins'                     => '#3A6F9A',
            'Dzins в смужку'            => '#3A6F9A',
            'небесний'                  => '#87CEEB',
            'blisk'                     => '#E5E4E2',
            'zovtij'                    => '#FFFF00',
            'sirij rubcik'              => '#8A8A8A',
            'sirij kosa'                => '#909090',
        ];
        return $colorMap[$colorName];
    }
}