<?php

namespace Craft;

class SearchPlus_AlgoliaMapService extends BaseApplicationComponent
{
	public $errors = [];
	public $basicMap = ['name' => 'Basic Mapping', 'handle' => 'basic', 'description' => 'Standard Content Mapping', 'plugin' => 'SearchPlus'];
	private $mapDepth = 0;
	private $maxMapDepth = 2;
	private $activeFieldMap = [];
    private $indexSources = [];

    public function init()
    {
        $pluginIndexSources = craft()->plugins->call('registerSearchPlusIndexSources');

        foreach ($pluginIndexSources as $pluginHandle => $pluginIndexSourceArray) {
            foreach ($pluginIndexSourceArray as $indexSource) {
                // Load up the index source class if we can
                $component = Craft::createComponent("Craft\\".$indexSource);
                if ($component)
                {
                    $this->indexSources[$component->elementType] = $component;
                }
            }
        }
    }

	public function getMappingFromHandle($handle)
	{
		if(strpos($handle, '.') > 0) {
			$details = $this->getMappingOptions($handle);
			if ($details == false) {
				$this->errors[] = 'Failed to find Mapping Option with handle : ' . $handle;
				return false;
			}

			return $details;
		} else {
			return $this->basicMap;
		}
	}


	public function getTestItem($elementId = '', $type = 'Entry')
	{
		$criteria = craft()->elements->getCriteria($type);
		// Grab a random entry
		if($elementId != '') {
			$criteria->id = $elementId;
		}

		$item = $criteria->first();

		return $item;
	}

	public function testOutput($handle, $item) 
	{
		$output = $this->mapItem($item, $handle);
		return $output;
	}


	public function mapItem($item, $handle)
	{
		if(strpos($handle, '.') > 0) {
			// This is a custom mapping
			$return = $this->thirdPartyMap($handle, $item);
			if($return == false) {
				return false;
			}
		} else {
			// Built in mapping.
			// Just basic for now
			$return = $this->basicMapping($item);
			$pluginReturn = craft()->plugins->call('searchPlus_alterBasicItemMapping', array($return, $item));
			if ($pluginReturn)
			{
				foreach($pluginReturn as $source => $altered) {
					if(is_array($altered)) {
						$return = $altered;
					}
				}
			}
		}

		return $return;
	}

	private function thirdPartyMap($mapHandle, $item)
	{

		$details = $this->getMappingOptions($mapHandle);
		if($details == false) {
			$this->errors[] = 'Failed to find Mapping Option with handle : '.$mapHandle;
			return false;
		}
		// Do we have a method?
		$method = null;
		$class = null;

		if(isset($details['method']) && $details['method'] != '') {
			$method = $details['method'];

			$split = explode('.', $details['method']);
			if(count($split) > 1) {

				$class = lcfirst($split[0]);
				$method = $split[1];
			} else {
				$class = lcfirst($details['plugin']);
				$method = $details['method'];
			}
		} else {
			$class = $details['plugin'];
			$method = 'searchPlusAlgoliaMapEntry';
		}


		if(is_null($class) || is_null($method)) {
			$this->errors[] = 'Map class or method is null. Class = '.$class.', method = '.$method;
			return false;
		}

		// Now check this is a valid method
		if(!isset(craft()->$class)) {
			$this->errors[] = 'Map class is not valid : '.$class;
			return false;
		}

		if(!method_exists(craft()->$class, $method)) {
			$this->errors[] = 'Method in class not valid or not defined. Class = '.$class.', with method : '.$method;
			return false;
		}

		return craft()->$class->$method($item);
	}

	private function basicMapping($item, $rootNode = true)
	{
		try {


			$temp = [];

			if($rootNode) {
				$temp['objectID'] = $item->id;
			}

			$keys = [
				'title' => 'title', 
				'id' => 'id', 
				'locale' => 'local',
				'uri' => 'uri', 
				'url' => 'url', 
				'slug' => 'slug',
				'enabled' => 'enabled',
				'archived' => 'archived',
				'localeEnabled' => 'localeEnabled',
				'postDate' => 'postDate',
				'dateCreated' => 'dateCreated', 
				'dateUpdated' => 'dateUpdated', 
				'expiryDate' => 'expiryDate'
			];
			$casts = [
				'localeEnabled' => 'bool', 
				'enabled' => 'bool',
				'archived' => 'bool',
				'postDate' => 'date',
				'dateCreated' => 'date', 
				'dateUpdated' => 'date', 
				'expiryDate' => 'date'
			];
			

			foreach($keys as $key => $attr) {
				$ret =  $this->getItemAttributeSafely($item, $attr);


				if(isset($casts[$key])) {
					switch($casts[$key]) {
						case 'bool': 
							$ret = (bool) $ret;
						break;
						case 'date': 
							$ret = $this->formatDateValue($ret);
						break;
					}
				}

				$temp[$key] = $ret;
			}
			$temp['absoluteUri'] = '/' . $temp['uri'];


			$temp['authorId'] = '0';
			$temp['authorName'] = '';
			if(isset($item->author) && !is_null($item->author)) {
				if(isset($item->author->id)) {
					$temp['authorId'] = $item->author->id;
				}
				if(isset($item->author->friendlyName)) {
					$temp['authorName'] = $item->author->friendlyName;
				}
			}

    
      $specifics = [];
      
		  // Content type specific parts
      if ($item->elementType === 'Asset') {
          $specifics = $this->mapAssetSpecifics($item);
      } elseif (isset($this->indexSources[$item->elementType])) {
          $specifics = $this->indexSources[$item->elementType]->mapSpecifics($item);
      }
      /*
			$specifics = [];
			// Content type specific parts
			switch($item->elementType) {
				case 'Entry' : {
					$specifics = $this->mapEntrySpecifics($item);
					break;
				}
				case 'Commerce_Product' : {
					$specifics = $this->mapCommerceProductSpecifics($item);
					break;
				}
				case 'Asset' : {
					$specifics = $this->mapAssetSpecifics($item);
					break;
				}
			}*/

			if(!is_null($specifics) && !empty($specifics)) {
				$temp = array_merge($temp, $specifics);
			}

			$content = $this->getFieldContentForItem($item);

  	/*	$specifics = [];
      
		  // Content type specific parts
      if ($item->elementType === 'Asset') {
          $specifics = $this->mapAssetSpecifics($item);
      } elseif (isset($this->indexSources[$item->elementType])) {
          $specifics = $this->indexSources[$item->elementType]->mapSpecifics($item);
      }
*/
			if(!is_null($content) && !empty($content)) {
				$temp = array_merge($temp, $content);
			}


			return $temp;

		} catch(\Exception $e) {
			// Some big failure. Usually something we can't recover from
			// We'll have to flag this item as unmapped and carry on

			craft()->searchPlus_log->error('Failed to create mapping for item', ['item' => $item, 'exception' => $e->getMessage()]);
			return false;
		}

	}

	private function mapAssetSpecifics($item)
	{
		if($item->elementType != 'Asset') return null;


		try { 
			$extra = [];
			$baseExtra = [];

			// Get all the predefined transforms
			$transforms = craft()->assetTransforms->getAllTransforms();
			foreach($transforms as $transform) {

				$fileModel = craft()->assets->getFileById($item->id);
				$transformIndexModel = craft()->assetTransforms->getTransformIndex($fileModel, $transform->handle);

				try
				{
					$url = craft()->assetTransforms->ensureTransformUrlByIndexModel($transformIndexModel);
					$temp['handle'] = $transform->handle;
					$temp['name'] = $transform->name;
					$temp['width'] = $transform->width;
					$temp['height'] = $transform->height;
					$temp['url'] = $url;

					$extra[] = $temp;
					$baseExtra['transform_'.$transform->handle] = $url;
				}
				catch (\Exception $e)
				{
					// Fail silently. This might not actually be a transformable asset
					craft()->searchPlus_log->softerror('Mapping failed creating tranformed asset', ['transform' => $transform, 'item' => $item, 'exception' => $e->getMessage()]);
				}
			}

			if(empty($extra)) return [];

			return array_merge($baseExtra, ['transforms' => $extra]);
		} catch(\Exception $e) {
			craft()->searchPlus_log->exception('Exception mapping asset', ['item' => $item, 'exception' => $e->getMessage()]);
			return null;
		}
	}

	public function getBaseContentForItem($item, $attr)
	{
		$return = [];
		foreach($attr as $key => $opt) {
			$val = null;

			if(isset($item->$key)) {
				$val = $item->$key;
				switch ($opt) {
					case 'bool' : {
						$val = (bool)$val;
						break;
					}
					case 'int' : {
						$val = (int)$val;
						break;
					}
					case 'number' : {
						$val = (double)$val;
						break;
					}
				}
			}
			$return[$key] = $val;
		}

		return $return;
	}

	public function getFieldContentForItem($element)
	{
		$temp = [];

		$fieldLayout = $element->getFieldLayout();

		foreach ($fieldLayout->getFields() as $fieldLayoutField)
		{
			$field = $fieldLayoutField->getField();

			if ($field)
			{
				$fieldType = $field->getFieldType();

				if ($fieldType)
				{
					$fieldType->element = $element;

					$handle = $field->handle;

					// Set the keywords for the content's locale
					$fieldContent = $element->getFieldValue($handle);
					//$fieldSearchKeywords = $fieldType->getSearchKeywords($fieldContent); // @todo - maybe use this later?


					$temp[$handle] = $this->getFullContentForFieldContent($field->type, $fieldContent, $fieldType, $handle);

				}
			}
		}

		return $temp;
	}

	private function getFullContentForFieldContent($type, $fieldContent, $fieldType, $fieldHandle)
	{

		$this->activeFieldMap[] = $fieldHandle;
		switch($type) {
			case 'RichText' : {
				if($fieldContent != null) {
					return $fieldContent->getParsedContent();
				}
				return null;
				break;
			}
			case 'Checkboxes' :
			case 'MultiSelect' : {
				$temp = [];
				foreach($fieldContent->getArrayCopy() as $optionData) {
					$temp[] = ['label' => $optionData->label, 'value' => $optionData->value];
				}
				return $temp;
			}
			case 'RadioButtons' :
			case 'Dropdown' : {
				return ['label' => $fieldContent->label, 'value' => $fieldContent->value];
			}
			case 'Lightswitch' : {
				if($fieldContent === true) return true;
				return false;
			}
			case 'Date' : {
				return $this->formatDateValue($fieldContent);
			}
			default : {
				if(is_null($fieldContent)) return null;
				if(is_object($fieldContent)) return $this->handleObjectFieldContent($type, $fieldContent, $fieldType);
				if(is_array($fieldContent)) return $this->handleArrayFieldContent($type, $fieldContent);
				if(is_bool($fieldContent)) return boolval($fieldContent);
				if(is_numeric($fieldContent)) return floatval($fieldContent);
				if(is_string($fieldContent)) return $fieldContent;
			}
		}

		return null;
	}

	private function handleArrayFieldContent($type, $fieldContent)
	{
		return $fieldContent;
	}

	private function handleObjectFieldContent($type, $fieldContent, $fieldType)
	{
		switch(true) {
			case $fieldContent instanceof ElementCriteriaModel : {
				return $this->mapElementCriteria($fieldContent);
				break;
			}
			case $fieldContent instanceof BaseElementModel : {
				return $this->getFieldContentForItem($fieldContent);
				break;
			}
			default : {
				if(method_exists($fieldContent, 'getContent')) {
					return $fieldContent->getContent();
				} elseif(method_exists($fieldContent, 'getAttributes')) {
					return $fieldContent->getAttributes();
				} else {
					// Not sure how to handle this
					// Third paty hooks maybe?
					return null;
				}
			}
		}
	}

	private function mapElementCriteria($criteria)
	{
		$this->mapDepth++;

		if($this->mapDepth > $this->maxMapDepth || !method_exists($criteria, 'find')) {
			$this->mapDepth--;
			return null;
		}

		// Ok. Get the stuff from this criteria
		$items = $criteria->find();
		$return = [];

		foreach($items as $item) {
			$temp = $this->basicMapping($item, false);

			if(!is_array($temp) || count($temp) < 1) {
				// Mapping failure. Mark as such			
				$return[] = false;	
			} else {
				$return[] = $temp;
			}
		}

		$this->mapDepth--;
		return $return;
	}

	public function getMappingOptions($handle = '')
	{

		$options[] = $this->basicMap;
		foreach (craft()->plugins->call('searchPlus_addAlgoliaMapping') as $key => $opt) {

			// Might be a sub array
			if (isset($opt[0])) {
				// Passing multiples
				foreach ($opt as $subkey => $subopt) {
					// Make sure we have have enough details
					$clean = $this->cleanThirdPartyMappingOption($subopt, $key, true);
					if ($clean != false) {
						$options[] = $clean;
					}
				}
			} else {
				$clean = $this->cleanThirdPartyMappingOption($opt, $key);
				if ($clean != false) {
					$options[] = $clean;
				}
			}
		}

		if($handle != '') {
			// Get a single option
			foreach($options as $opt) {
				if($opt['handle'] == $handle) {
					return $opt;
				}
			}

			return false;
		}

		return $options;
	}

	private function cleanThirdPartyMappingOption($arr = [], $pluginName, $requireMethod = false)
	{
		if (empty($arr)) return false;

		$ret = [];

		foreach (['name', 'description', 'handle'] as $key) {
			if (isset($arr[$key]) && $arr[$key] != '') {
				$ret[$key] = $arr[$key];
			} else {
				// Nope!
				return false;
			}
		}

		if ((!isset($arr['method']) || $arr['method'] == '') && $requireMethod) {
			return false;
		} else if (isset($arr['method']) && $arr['method'] != '') {
			$ret['method'] = $arr['method'];
		}

		$ret['plugin'] = ucfirst($pluginName);
		// Set the handle to contain the plugin name
		$ret['handle'] = $pluginName . '.' . $ret['handle'];

		return $ret;
	}

	public function saveMap(SearchPlus_AlgoliaMapModel $map)
	{
		$action = 'new';

		if(!$map->validate())
		{
			return false;
		}

		if($map->id != null) {
			$action = 'update';
			$record = SearchPlus_AlgoliaMapRecord::model()->findById($map->id);
		}
		else {
			$record = new SearchPlus_AlgoliaMapRecord();
		}

		// Clean up the map slightly
		$sectionMap = [];
		$elements = $map->sectionMap['elements'];
		$options = $map->sectionMap['options'];

		foreach($elements as $element) {
            $sectionMap['elements'][] = $element;
			if(isset($options[$element])) {
				$sectionMap['options'][$element] = $options[$element];
			}
		}

		$record->handle = $map->handle;
		$record->name = $map->name;
		$record->indexMap = ['*' => $map->handle];
		$record->sectionMap = $sectionMap;
		$record->contentMap = $map->contentMap;
		$record->status = $map->status;
		$record->enabledMulti = false; // for now
		$record->save();
		$map->id = $record->id;


		if($action == 'update') {
			craft()->searchPlus_log->success('Updated index - '.$map->name, ['map' => $map]);
		} else {
			craft()->searchPlus_log->success('Created new index - '.$map->name, ['map' => $map]);
		}

		return $map;
	}

	private function formatDateValue($dateObj)
	{
		if ($dateObj == null) return [];

		$temp = [];
		$temp['localeDate'] = $dateObj->localeDate();
		$temp['localeTime'] = $dateObj->localeTime();
		$temp['nice'] = $dateObj->nice();
		$temp['mysql'] = $dateObj->mySqlDateTime();
		$temp['cookie'] = $dateObj->cookie();
		$temp['timestamp'] = $dateObj->format('U');

		return $temp;
	}

	private function getItemAttributeSafely($item, $attribute, $fallback = null) 
	{
		try { 
			return $item->$attribute;
		} catch(\Exception $e) {
			return $fallback;
		};
	}
}
