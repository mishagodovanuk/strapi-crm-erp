<?php

namespace Mihod\MarylineService;

use KeyCrm\KeyCrmApi;
use KeyCrm\Client\DefaultHttpClient;
use KeyCrm\Exception\KeyCrmException;
use KeyCrm\Model\Category;
use \Transliterator;

/**
 * Category syncer service.
 */
class CategorySyncer
{
    /**
     * @var KeyCrmApi
     */
    private KeyCrmApi $keyCrmApi;

    /**
     * @var string
     */
    private string $strapiBaseUrl;

    /**
     * @var string|null
     */
    private ?string $strapiToken;

    /**
     * @var array
     */
    private array $keyCrmList = [];

    /**
     * @var array
     */
    private array $mapping = [];

    /**
     * @param string $keyCrmBaseUrl
     * @param string $keyCrmToken
     * @param string $strapiBaseUrl
     * @param string|null $strapiToken
     */
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
     * Sync categories from KeyCRM into Strapi.
     * For each category from KeyCRM, check if it exists in Strapi by keycrm_id:
     * - If it exists, update it.
     * - Otherwise, create a new Strapi category.
     * Parent-child relationships are maintained using the parent's KeyCRM ID.
     */
    public function syncCategories(): void
    {
        $keycrmCategories = $this->keyCrmApi->categories()->list();

        $this->keyCrmList = $keycrmCategories;

        foreach ($keycrmCategories as $keycrmCat) {
            $existingCategory = $this->getStrapiCategoryByKeycrmId($keycrmCat->id);

            if ($existingCategory) {
                //$this->updateStrapiCategory($existingCategory['id'], $keycrmCat);
                //$this->mapping[$keycrmCat->id] = $existingCategory['id'];
                //echo "Updated Strapi category for KeyCRM category '{$keycrmCat->name}' (KeyCRM ID: {$keycrmCat->id}).\n";
            } else {
                $newCategory = $this->createStrapiCategory($keycrmCat);

                if ($newCategory && isset($newCategory['id'])) {
                    $this->mapping[$keycrmCat->id] = $newCategory['id'];
                    echo "Created new Strapi category for KeyCRM category '{$keycrmCat->name}' with Strapi ID: " . $newCategory['id'] . ".\n";
                }
            }
        }
    }

    /**
     * Get a Strapi category by its keycrm_id.
     *
     * @param int $keycrmId
     * @return array|null Returns the first matching category as an array, or null if not found.
     * @throws \Exception
     */
    private function getStrapiCategoryByKeycrmId(int $keycrmId): ?array
    {
        $url = $this->strapiBaseUrl . '/api/categories?populate=*&filters[keycrm_id][$eq]=' . $keycrmId;
        $response = $this->curlGet($url);

        if (isset($response['data']) && count($response['data']) > 0) {
            $cat = $response['data'][0];

            return [
                'id' => $cat['id'],
                'existed' => true
            ];
        }

        return null;
    }

    /**
     * Get or create a Strapi category by KeyCRM category ID.
     * This method is recursive: if the parent does not exist in Strapi,
     * it will fetch the parent from KeyCRM and create it first.
     *
     * @param int $keycrmId
     * @return array|null Returns the Strapi category record (with 'id' and 'attributes')
     *                    or null if it cannot be created.
     * @throws \Exception
     */
    private function getOrCreateStrapiCategoryByKeycrmId(int $keycrmId): ?array
    {
        $existing = $this->getStrapiCategoryByKeycrmId($keycrmId);

        if ($existing) {
            return $existing;
        }

        $parentKeycrmCat = $this->getKeyCrmById($keycrmId);

        if (!$parentKeycrmCat) {
            return null;
        }

        $parentCategories = [];

        if ($parentKeycrmCat->parent_id) {
            $grandParentStrapi = $this->getOrCreateStrapiCategoryByKeycrmId($parentKeycrmCat->parent_id);

            if ($grandParentStrapi) {

                if (isset($grandParentStrapi['existed']) && $grandParentStrapi['existed'] == true) {
                    $parentCategories[] = [$grandParentStrapi['id'] - 1];
                } else {
                    $parentCategories[] = [$grandParentStrapi['id'] - 1];
                }
            }
        }

        $slug = $this->transliterate($this->transliterate($parentKeycrmCat->name));

        $data = [
            'data' => [
                'name'              => $parentKeycrmCat->name,
                'keycrm_id'         => $parentKeycrmCat->id,
                'parent_categories' => $parentCategories,
                'slug'              => strtolower($slug),
                'collection'        => false
            ]
        ];

        return $this->curlPost($this->strapiBaseUrl . '/api/categories', $data)['data'];
    }

    /**
     * Create a new category in Strapi based on a KeyCRM category.
     * This method now uses getOrCreateStrapiCategoryByKeycrmId() to ensure
     * that the parent category exists in Strapi.
     *
     * @param Category $keycrmCat
     * @return array|null The Strapi API response.
     * @throws \Exception
     */
    private function createStrapiCategory(Category $keycrmCat): ?array
    {
        $url = $this->strapiBaseUrl . '/api/categories';
        $parentCategories = [];

        if ($keycrmCat->parent_id) {
            $parent = $this->getOrCreateStrapiCategoryByKeycrmId($keycrmCat->parent_id);

            if ($parent) {
                if (isset($parent['existed']) && $parent['existed'] == true) {
                    $parentCategories[] = [$parent['id'] - 1];
                } else {
                    $parentCategories[] = [$parent['id'] - 1];
                }
            }
        }

        $slug = $this->transliterate($this->transliterate($keycrmCat->name));

        $data = [
            'data' => [
                'name'              => $keycrmCat->name,
                'keycrm_id'         => $keycrmCat->id,
                'parent_categories' => $parentCategories,
                'slug'              => strtolower($slug),
                'collection'        => false
            ]
        ];

        return $this->curlPost($this->strapiBaseUrl . '/api/categories', $data)['data'];
    }


    /**
     * Update an existing Strapi category with data from a KeyCRM category.
     *
     * @param int|string $strapiId
     * @param Category $keycrmCat
     * @return array|null The Strapi API response.
     * @throws \Exception
     */
    private function updateStrapiCategory($strapiId, Category $keycrmCat): ?array
    {
        $url = $this->strapiBaseUrl . '/api/categories/' . $strapiId;
        $parentCategories = [];

        if ($keycrmCat->parent_id && isset($this->mapping[$keycrmCat->parent_id])) {
            $parentCategories[] = ['id' => $this->mapping[$keycrmCat->parent_id]];
        }

        $data = [
            'data' => [
                'name' => $keycrmCat->name,
                'keycrm_id' => $keycrmCat->id,
                'parent_categories' => $parentCategories
            ]
        ];

        return $this->curlPut($url, $data);
    }

    /**
     * @param $id
     * @return mixed|null
     */
    private function getKeyCrmById($id): ?Category
    {
        $categories = $this->keyCrmList;

        foreach ($categories as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Perform a GET request using cURL.
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
     * Perform a POST request using cURL.
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

    /**
     * Perform a PUT request using cURL.
     */
    private function curlPut(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = ['Content-Type: application/json'];

        if ($this->strapiToken) {
            $headers[] = 'Authorization: Bearer ' . $this->strapiToken;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('PUT request error: ' . curl_error($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * @param string $text
     * @return string|null
     */
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
