<?php

namespace Kaliop\eZMigrationBundle\Core\Manager;

use eZ\Publish\API\Repository\Values\Content\Location;
use Kaliop\eZMigrationBundle\Core\ReferenceHandler;

class LocationManager extends AbstractManager
{

    /**
     * Method to handle the create operation of the migration instructions
     */
    public function create()
    {
        if (!isset($this->dsl['match'])) {
            throw new \Exception('Match condition is required to perform location create operations.');
        }

        $match = $this->dsl['match'];

        if (!isset($match['content_id']) &&
            !isset($match['location_id']) &&
            !isset($match['content_remote_id']) &&
            !isset($match['location_remote_id']) &&
            !isset($match['parent_location_id']) &&
            !isset($match['parent_location_remote_id'])
        ) {
          throw new \Exception('Either the ID or remote ID of a content, the ID or remote ID of a location or the id or remote ID
          of the parent location of the contents you want to create a new location are required to create a new location.');
        }

        if (count($match) > 1) {
            throw new \Exception('Only one condition is allowed by now');
        }

        if (!isset($this->dsl['parent_location_id'])) {
            throw new \Exception('Missing parent location id. This is required to create the new location.');
        }

        // convert the references passed in the match
        // @todo probably can be moved to a separate method.
        foreach ($match as $condition => $values) {
            if (is_integer($values) && $this->isReference($values)) {
                $match[$condition] = $this->getReference($values);
            } elseif (is_array($values)) {
                foreach ($values as $position => $value) {
                    if ($this->isReference($value)) {
                        $match[$condition][$position] = $this->getReference($value);
                    }
                }
            }
        }

        $this->loginUser();

        // @TODO: see if this can be simplified somehow
        $contentCollection = $this->container->get('ez_migration_bundle.content_matcher')->matchContent($match);

        $locationService = $this->repository->getLocationService();

        if (!is_array($this->dsl['parent_location_id'])) {
            $this->dsl['parent_location_id'] = array($this->dsl['parent_location_id']);
        }

        /// @todo use foreach :-)
        while ($contentCollection->valid()) {
            $content = $contentCollection->current();
            $contentInfo = $content->contentInfo;

            foreach ($this->dsl['parent_location_id'] as $parentLocationId) {
                if ($this->isReference($parentLocationId)) {
                    $parentLocationId = $this->getReference($parentLocationId);
                }
                $locationCreateStruct = $locationService->newLocationCreateStruct($parentLocationId);

                $locationCreateStruct->hidden = isset($this->dsl['is_hidden']) ?: false;

                if (isset($this->dsl['priority'])) {
                    $locationCreateStruct->priority = $this->dsl['priority'];
                }

                $locationCreateStruct->sortOrder = $this->getSortOrder();
                $locationCreateStruct->sortField = $this->getSortField();

                $locationService->createLocation($contentInfo, $locationCreateStruct);
            }

            $contentCollection->next();
        }
    }

    /**
     * Method to handle the update operation of the migration instructions
     *
     * Updates basic information for a location like priority, sort field and sort order.
     * Updates the visibility of the location when needed
     *
     * Can move a location and it's children to a new parent location or swap two locations
     */
    public function update()
    {
        if (!isset($this->dsl['location_id'])) {
            throw new \Exception('No location set for update.');
        }

        if (isset($this->dsl['swap_with_location']) && isset($this->dsl['patent_location_id'])) {
            throw new \Exception('Cannot move location to a new parent and swap location with another location at the same time.');
        }

        $this->loginUser();

        $locationService = $this->repository->getLocationService();

        $locationId = $this->dsl['location_id'];
        if ($this->isReference($locationId)) {
            $locationId = $this->getReference($locationId);
        }

        $location = $locationService->loadLocation($locationId);

        if (array_key_exists('priority', $this->dsl)
            || array_key_exists('sort_field', $this->dsl)
            || array_key_exists('sort_order', $this->dsl)
        ) {
            $locationUpdateStruct = $locationService->newLocationUpdateStruct();

            if (isset($this->dsl['priority'])) {
                $locationUpdateStruct->priority = $this->dsl['priority'];
            }

            if (isset($this->dsl['sort_field'])) {
                $locationUpdateStruct->sortField = $this->getSortField($location->sortField);
            }

            if (isset($this->dsl['sort_order'])) {
                $locationUpdateStruct->sortOrder = $this->getSortOrder($location->sortOrder);
            }

            $locationService->updateLocation($location, $locationUpdateStruct);
        }

        // Check if visibility needs to be updated
        if (isset($this->dsl['is_hidden'])) {
            if ($this->dsl['is_hidden']) {
                $locationService->hideLocation($location);
            } else {
                $locationService->unhideLocation($location);
            }
        }

        // Move or swap location
        if (isset($this->dsl['parent_location_id'])) {
            // Move the location and all it's children to a new parent
            $parentLocationId = $this->dsl['parent_location_id'];
            if ($this->isReference($parentLocationId)) {
                $parentLocationId = $this->getReference($parentLocationId);
            }
            $newParentLocation = $locationService->loadLocation($parentLocationId);
            $locationService->moveSubtree($location, $newParentLocation);
        } elseif (isset($this->dsl['swap_with_location'])) {
            //Swap locations
            $swapLocationId = $this->dsl['swap_with_location'];
            if ($this->isReference($swapLocationId)) {
                $swapLocationId = $this->getReference($swapLocationId);
            }
            $locationToSwap = $locationService->loadLocation($swapLocationId);

            $locationService->swapLocation($location, $locationToSwap);
        }

        $this->setReferences($location);
    }

    /**
     * Method to handle the delete operation of the migration instructions
     *
     * Delete locations identified by their ids.
     */
    public function delete()
    {
        if (!isset($this->dsl['location_id'])) {
            throw new \Exception('No location provided for deletion');
        }

        $this->loginUser();

        $locationService = $this->repository->getLocationService();

        if (!is_array($this->dsl['location_id'])) {
            $this->dsl['location_id'] = array($this->dsl['location_id']);
        }

        foreach ($this->dsl['location_id'] as $locationId) {
            $location = $locationService->loadLocation($locationId);
            $locationService->deleteLocation($location);
        }
    }

    protected function getSortField($currentValue = null)
    {
        $sortField = Location::SORT_FIELD_PUBLISHED;

        if (!is_null($currentValue)) {
            $sortField = $currentValue;
        }

        if (isset($this->dsl['sort_field'])) {
            $sortFieldId = "SORT_FIELD_" . strtoupper($this->dsl['sort_field']);

            $ref = new \ReflectionClass('eZ\Publish\API\Repository\Values\Content\Location');

            $sortField = $ref->getConstant($sortFieldId);
        }

        return $sortField;
    }

    /**
     * Get the sort order based on the current value and the value in the DSL definition.
     *
     * If no current value is set and there is no value in the DSL it will default to Location::SORT_ORDER_ASC
     *
     * @see \eZ\Publish\API\Repository\Values\Content\Location::SORT_ORDER_*
     *
     * @param int $currentValue
     * @return int
     */
    protected function getSortOrder($currentValue = null)
    {
        $sortOrder = Location::SORT_ORDER_ASC;
        if (!is_null($currentValue)) {
            $sortOrder = $currentValue;
        }

        if (isset($this->dsl['sort_order'])) {
            if (strtoupper($this->dsl['sort_order']) === 'ASC') {
                $sortOrder = Location::SORT_ORDER_ASC;
            } else {
                $sortOrder = Location::SORT_ORDER_DESC;
            }
        }

        return $sortOrder;
    }

    /**
     * Sets references to object attributes
     *
     * The Location Manager currently supports setting references to location id.
     *
     * @throws \InvalidArgumentException When trying to set a reference to an unsupported attribute.
     * @param \eZ\Publish\API\Repository\Values\Content\Location $location
     * @return boolean
     */
    protected function setReferences($location)
    {
        if (!array_key_exists('references', $this->dsl)) {
            return false;
        }
        $referenceHandler = ReferenceHandler::instance();

        foreach ($this->dsl['references'] as $reference) {
            switch ($reference['attribute']) {
                case 'location_id':
                case 'id':
                    $value = $location->id;
                    break;
                default:
                    throw new \InvalidArgumentException('Location Manager does not support setting references for attribute ' . $reference['attribute']);
            }

            $referenceHandler->addReference($reference['identifier'], $value);
        }

        return true;
    }
}
