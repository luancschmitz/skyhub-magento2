<?php
/**
 * BitTools Platform | B2W - Companhia Digital
 *
 * Do not edit this file if you want to update this module for future new versions.
 *
 * @category  BitTools
 * @package   BitTools_SkyHub
 *
 * @copyright Copyright (c) 2018 B2W Digital - BitTools Platform.
 *
 * @author    Julio Reis <julio.reis@b2wdigital.com>
 */

namespace BitTools\SkyHub\Model\Customer\Attributes;

use BitTools\SkyHub\Api\Data;
use BitTools\SkyHub\Api\CustomerAttributeMappingRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class MappingRepository implements CustomerAttributeMappingRepositoryInterface
{
    
    /** @var MappingFactory */
    protected $mappingFactory;
    
    
    public function __construct(MappingFactory $mappingFactory)
    {
        $this->mappingFactory = $mappingFactory;
    }
    
    
    /**
     * Retrieve all attributes for entity type
     *
     * @param SearchCriteriaInterface $searchCriteria
     *
     * @return mixed
     */
    public function getList(SearchCriteriaInterface $searchCriteria)
    {
        // TODO: Implement getList() method.
    }
    
    
    /**
     * @param $mappingId
     *
     * @return Mapping|mixed
     *
     * @throws NoSuchEntityException
     */
    public function get($mappingId)
    {
        /** @var Mapping $mapping */
        $mapping = $this->mappingFactory->create();
        $mapping->load($mappingId);
    
        if (!$mapping->getId()) {
            throw new NoSuchEntityException(__('Attribute Mapping with id "%1" does not exist.', $mappingId));
        }
        
        return $mapping;
    }
    
    
    /**
     * @param Data\CustomerAttributeMappingInterface $mapping
     *
     * @return mixed
     */
    public function save(Data\CustomerAttributeMappingInterface $mapping)
    {
        $mapping->save();
        return $this;
    }
    
    
    /**
     * @param Data\CustomerAttributeMappingInterface $mapping
     *
     * @return mixed
     */
    public function delete(Data\CustomerAttributeMappingInterface $mapping)
    {
        $mapping->delete();
        return $this;
    }
    
    
    /**
     * @param int $mappingId
     *
     * @return mixed
     */
    public function deleteById($mappingId)
    {
        /** @var Mapping $mapping */
        $mapping = $this->mappingFactory->create();
        $mapping->setId($mappingId);
        $this->delete($mapping);
        
        return $this;
    }
}