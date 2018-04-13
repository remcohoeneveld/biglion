<?php
namespace Craft;

class SearchPlus_CommerceProductIndexSource extends SearchPlus_BaseIndexSource
{

    public $elementType = 'Commerce_Product';

    /**
     * Gets the variable array for product types, used in the CP edit views
     *
     * @return array
     */
    public function getOptions()
    {
        $return = [
            'name' => $this->elementType,
            'label' => 'Commerce Products'
        ];

        $productTypes = [];
        foreach (craft()->commerce_productTypes->getAllProductTypes() as $type) {
            $productTypes[] = [
                'label' => $type->name,
                'value' => $type->id
            ];
        }

        $return['options'][] = [
            'name' => 'Product Types',
            'handle' => 'products', // TODO: change to `productTypes`
            'instructions' => 'What product types do you want indexed?',
            'options' => $productTypes
        ];

        return $return;
    }

    /**
     * Gets the product type name by ID.
     *
     * @param $sourceId
     *
     * @return string
     */
    public function getSourceName($sourceId)
    {
        $type = craft()->commerce_productTypes->getProductTypeById($sourceId);

        if(is_null($type)) return '';
        return $type->name;
    }

    /**
     * Counts the number of commerce products by the type id
     *
     * @param $optionSet
     *
     * @return int
     */
    public function getCountsForOptionSet($optionSet)
    {

        if(isset($optionSet['Commerce_Product']['products'])) {

            $productTypes = $optionSet['Commerce_Product']['products'];

            if (empty($productTypes)) {
                return 0;
            }

            $c = 0;

            $inSections = '('.implode(',', $productTypes).')';
            // Build a query for this
            $query = craft()->db->createCommand();
            $query->select('count(*) c, products.typeId')
                ->from('commerce_products products')
                ->join('elements elements', 'products.id = elements.id')
                ->where('products.typeId IN '.$inSections)
                ->andWhere('elements.enabled = 1')
                ->group('products.typeId');


            $res = $query->queryAll();

            $c = 0;
            foreach ($res as $row) {
                $c += $row['c'];
            }

            return $c;
        }

    }

    /**
     * Queues the product items for later population by product type id
     * We'll do this direct by an insert-select for maximum SPEEDZ
     *
     * @param $optionSet
     * @param $mappingId
     *
     * @return bool
     */
    public function queueItemsForPopulation($optionSet, $mappingId)
    {

        if(isset($optionSet['Commerce_Product']['products'])) {

            $productTypes = $optionSet['Commerce_Product']['products'];

            if (empty($productTypes)) {
                return false;
            }

            $inSections = '('.implode(',', $productTypes).')';
            $currentTime = DateTimeHelper::currentTimeForDb();
            $sql = "INSERT INTO craft_searchplus_indexitem (elementId, elementType, mappingId, status, dateCreated, dateUpdated)
SELECT products.id, 'Commerce_Product', ".$mappingId.", 'pending', '".$currentTime."', '".$currentTime."'
FROM craft_commerce_products products
JOIN craft_elements elements ON products.id = elements.id
WHERE elements.enabled = 1
AND products.typeId IN ".$inSections;

            craft()->db->createCommand($sql)->execute();
        }

        return true;

    }

    /**
     * Handles the product on save event.
     *
     * @param Commerce_ProductModel $product
     *
     * @return bool|void
     */
    public function onSave(Commerce_ProductModel $product)
    {
        $maps = $this->getMapsForSource($product->typeId, $this->elementType, 'products'); // There might be multiple

        if (empty($maps)) {
            return;
        }

        foreach ($maps as $map) {

            // This looks to be valid, so we'll pass it onward for population against it's map
            $temp = $this->mapItem($product, $map);

            if ($temp === false) {
                return;
            }

            $batch = [];
            $batch[] = $temp;

            $index = $map->indexMap['*'];  // @todo this is a hack to later enable multi indexes


            if (!craft()->searchPlus_algolia->canPopulateUnlimited()) {
                // We need to check if this goes too far beyond the limit
                $algoliaIndex = craft()->searchPlus_algolia->getIndexByName($index);
                if ($algoliaIndex !== false) {
                    if ($algoliaIndex->entries > craft()->searchPlus_algolia->itemFreeLimit) {
                        return;
                    }
                }
            }

            //craft()->searchPlus_log->success('element_indexed');//, ['elementId' => $entry->id, 'title' => $entry->title, 'type' => 'Entry'];
            craft()->searchPlus_algolia->saveObjects($index, $batch);
        }

        return true;

    }

    /**
     * Handles the product on delete event.
     *
     * @param Commerce_ProductModel $product
     *
     * @return bool|void
     */
    public function onDelete(Commerce_ProductModel $product)
    {
        $maps = $this->getMapsForSource($product->typeId, $this->elementType, 'products'); // There might be multiple

        if (empty($maps)) {
            return;
        }

        foreach ($maps as $map) {
            // This looks to be valid, so we'll pass downward for removal
            $index = $map->indexMap['*'];

            $this->deleteObject($index, $product->id);
        }

        return true;
    }

    /**
     * Map product specific bits.
     *
     * @param Commerce_ProductModel $product
     *
     * @return array
     */
    public function mapSpecifics(Commerce_ProductModel $product)
    {

        $return = [];

        $attr = [
            'freeShipping' => 'bool',
            'promotable' => 'bool',
            'typeId' => 'int',
            'defaultPrice' => 'number',
            'defaultVariantId' => 'string',
            'defaultSku' => 'string',
            'defaultWeight' => 'number',
            'defaultLength' => 'number',
            'defaultWidth' => 'number',
            'defaultHeight' => 'number',
            'taxCategoryId' => 'int'
        ];

        // Commerce does not be giving us the proper default values.
        // fix that by getting the default variant to start
        $defaultVariant = $product->getDefaultVariant();
        if($defaultVariant != null) {
            $product->defaultVariantId = $defaultVariant->getPurchasableId();
            $product->defaultSku = $defaultVariant->getSku();
            $product->defaultPrice = $defaultVariant->price * 1;
            $product->defaultHeight = $defaultVariant->height * 1;
            $product->defaultLength = $defaultVariant->length * 1;
            $product->defaultWidth = $defaultVariant->width * 1;
            $product->defaultWeight = $defaultVariant->weight * 1;
        }

        $base = craft()->searchPlus_algoliaMap->getBaseContentForItem($product, $attr);
        $content = craft()->searchPlus_algoliaMap->getFieldContentForItem($product);

        // Add the variants to the base
        foreach($product->getVariants() as $variant) {
            $temp = $this->_mapVariantSpecifics($variant);
            if($variant->id == $defaultVariant->id) {
                $base['defaultVariant'] = $temp;
            }
            $base['variants'][] = $temp;
        }

        return array_merge($base, $content);
    }

    /**
     * Map variant specific bits.
     *
     * @param Commerce_VariantModel $variant
     *
     * @return array
     */
    private function _mapVariantSpecifics(Commerce_VariantModel $variant)
    {
        $attr = [
            'isDefault' => 'bool',
            'unlimitedStock' => 'bool',
            'sku' => 'string',
            'id' => 'string',
            'slug' => 'string',
            'uri' => 'string',
            'productId' => 'string',
            'sortOrder' => 'int',
            'width' => 'number',
            'height' => 'number',
            'length' => 'number',
            'weight' => 'number',
            'stock' => 'int',
            'minQty' => 'string',
            'maxQty' => 'string'
        ];

        $base = craft()->searchPlus_algoliaMap->getBaseContentForItem($variant, $attr);
        $content = craft()->searchPlus_algoliaMap->getFieldContentForItem($variant);

        return array_merge($base, $content);
    }

}