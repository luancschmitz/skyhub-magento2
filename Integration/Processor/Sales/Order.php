<?php

namespace BitTools\SkyHub\Integration\Processor\Sales;

use BitTools\SkyHub\Integration\Context as IntegrationContext;
use BitTools\SkyHub\Integration\Processor\AbstractProcessor;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Model\Data\AddressFactory;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\DataObject;
use Magento\Catalog\Model as CatalogModelDir;
use Magento\Store\Model\Store;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use BitTools\SkyHub\Integration\Support\Sales\Order\CreateFactory as OrderCreatorFactory;
use BitTools\SkyHub\Helper\Sales\Order as OrderHelper;
use BitTools\SkyHub\Integration\Processor\Sales\Order\Status as StatusProcessor;

class Order extends AbstractProcessor
{
    
    use \BitTools\SkyHub\Traits\Customer;
    
    /** @var string */
    const ADDRESS_TYPE_BILLING  = \BitTools\SkyHub\Integration\Support\Sales\Order\Create::ADDRESS_TYPE_BILLING;
    
    /** @var string */
    const ADDRESS_TYPE_SHIPPING = \BitTools\SkyHub\Integration\Support\Sales\Order\Create::ADDRESS_TYPE_SHIPPING;
    
    
    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var CustomerRepositoryInterface */
    protected $customerRepository;

    /** @var AddressRepositoryInterface */
    protected $addressRepository;

    /** @var AddressFactory */
    protected $addressFactory;

    /** @var CustomerInterfaceFactory */
    protected $customerFactory;

    /** @var RegionInterfaceFactory */
    protected $regionFactory;

    /** @var OrderCreatorFactory */
    protected $orderCreatorFactory;

    /** @var OrderHelper */
    protected $orderHelper;

    /** @var StatusProcessor */
    protected $statusProcessor;
    
    /** @var array|AddressInterface[] */
    protected $addresses = [
        self::ADDRESS_TYPE_BILLING  => null,
        self::ADDRESS_TYPE_SHIPPING => null,
    ];


    public function __construct(
        IntegrationContext $integrationContext,
        OrderRepositoryInterface $orderRepository,
        ProductRepositoryInterface $productRepository,
        CustomerRepositoryInterface $customerRepository,
        AddressRepositoryInterface $addressRepository,
        AddressFactory $addressFactory,
        CustomerInterfaceFactory $customerFactory,
        RegionInterfaceFactory $regionFactory,
        OrderCreatorFactory $orderCreatorFactory,
        OrderHelper $orderHelper,
        StatusProcessor $statusProcessor
    )
    {
        parent::__construct($integrationContext);

        $this->orderRepository     = $orderRepository;
        $this->productRepository   = $productRepository;
        $this->customerRepository  = $customerRepository;
        $this->addressRepository   = $addressRepository;
        $this->addressFactory      = $addressFactory;
        $this->customerFactory     = $customerFactory;
        $this->regionFactory       = $regionFactory;
        $this->orderCreatorFactory = $orderCreatorFactory;
        $this->orderHelper         = $orderHelper;
        $this->statusProcessor     = $statusProcessor;
    }


    /**
     * @param array $data
     *
     * @return bool|SalesOrder
     *
     * @throws \Exception
     */
    public function createOrder(array $data)
    {
        try {
            /** @var SalesOrder $order */
            $order = $this->processOrderCreation($data);
        } catch (\Exception $e) {
            $this->eventManager()
                ->dispatch('bseller_skyhub_order_import_exception', [
                    'exception' => $e,
                    'order_data' => $data,
                ]);

            $this->logger()->critical($e);

            return false;
        }

        if ($order && $order->getId()) {
            $this->updateOrderStatus($data, $order);
        }

        return $order;
    }


    /**
     * @param array $data
     *
     * @return bool|SalesOrder
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     * @throws \Exception
     */
    protected function processOrderCreation(array $data)
    {
        $code    = $this->arrayExtract($data, 'code');
        $channel = $this->arrayExtract($data, 'channel');
        $orderId = $this->getOrderId($code);

        if ($orderId) {
            /**
             * @var SalesOrder $order
             *
             * Order already exists.
             */
            $order = $this->orderRepository->get($orderId);
            return $order;
        }

//        $this->simulateStore($this->getStore());

        $billingAddress  = new DataObject($this->arrayExtract($data, 'billing_address'));
        $shippingAddress = new DataObject($this->arrayExtract($data, 'shipping_address'));

        $customerData = (array) $this->arrayExtract($data, 'customer', []);
        $customerData = array_merge_recursive(
            $customerData,
            [
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress
            ]
        );

        /** @var CustomerInterface $customer */
        $customer = $this->getCustomer($customerData);

        $shippingCarrier = (string) $this->arrayExtract($data, 'shipping_carrier');
        $shippingMethod  = (string) $this->arrayExtract($data, 'shipping_method');
        $shippingCost    = (float)  $this->arrayExtract($data, 'shipping_cost', 0.0000);
        $discountAmount  = (float)  $this->arrayExtract($data, 'discount', 0.0000);
        $interestAmount  = (float)  $this->arrayExtract($data, 'interest', 0.0000);

        /** @var \BitTools\SkyHub\Integration\Support\Sales\Order\Create $creator */
        $creator = $this->orderCreatorFactory->create();

        $info = new DataObject(['send_confirmation' => 0]);

        $incrementId = $this->orderHelper->getNewOrderIncrementId($code);

        if ($incrementId) {
            $info->setData('increment_id', $incrementId);
        }

        $creator->setOrderInfo($info)
            ->setCustomer($customer)
            // ->setShippingMethod($shippingMethod, $shippingCarrier, (float) $shippingCost)
            ->setShippingMethod('freeshipping', 'freeshipping', (float) $shippingCost)
            ->setPaymentMethod('bseller_skyhub_standard')
            ->setDiscountAmount($discountAmount)
            ->setInterestAmount($interestAmount)
            ->addOrderAddress($this->getBillingAddress(), self::ADDRESS_TYPE_BILLING)
            ->addOrderAddress($this->getShippingAddress(), self::ADDRESS_TYPE_SHIPPING)
            ->setComment('This order was automatically created by SkyHub import process.');

        $products = $this->getProducts((array) $this->arrayExtract($data, 'items'));
        if (empty($products)) {
            throw new \Exception(__('The SkyHub products cannot be matched with Magento products.'));
        }

        /** @var array $productData */
        foreach ((array) $products as $productData) {
            $creator->addProduct($productData);
        }

        /** @var SalesOrder $order */
        $order = $creator->create();

        if (!$order) {
            return false;
        }

        $order->setData('bseller_skyhub', true);
        $order->setData('bseller_skyhub_code', $code);
        $order->setData('bseller_skyhub_channel', $channel);
        $order->setData('bseller_skyhub_json', json_encode($data));

        $this->orderRepository->save($order);

        $order->setData('is_created', true);

        return $order;
    }


    /**
     * @param array      $skyhubOrderData
     * @param SalesOrder $order
     *
     * @return $this
     *
     * @throws \Exception
     */
    protected function updateOrderStatus(array $skyhubOrderData, SalesOrder $order)
    {
        $skyhubStatusCode = $this->arrayExtract($skyhubOrderData, 'code');
        $skyhubStatusType = $this->arrayExtract($skyhubOrderData, 'status/type');

        /**
         * @todo Update this code to get the correct processor.
         */
        $this->statusProcessor->processOrderStatus($skyhubStatusCode, $skyhubStatusType, $order);

        return $this;
    }


    /**
     * @param array $items
     *
     * @return array
     */
    protected function getProducts(array $items)
    {
        $products = [];

        foreach ($items as $item) {
            $parentSku    = $this->arrayExtract($item, 'product_id');
            $childSku     = $this->arrayExtract($item, 'id');
            $qty          = $this->arrayExtract($item, 'qty');

            $price        = (float) $this->arrayExtract($item, 'original_price');
            $specialPrice = (float) $this->arrayExtract($item, 'special_price');

            $finalPrice = $price;
            if (!empty($specialPrice)) {
                $finalPrice = $specialPrice;
            }

            if (!$productId = $this->getProductIdBySku($parentSku)) {
                continue;
            }

            $data = [
                'product_id'    => (int)    $productId,
                'product_sku'   => (string) $parentSku,
                'qty'           => (float)  ($qty ? $qty : 1),
                'price'         => (float)  $price,
                'special_price' => (float)  $specialPrice,
                'final_price'   => (float)  $finalPrice,
            ];

            if ($childId = $this->getProductIdBySku($childSku)) {
                $data['children'][] = [
                    'product_id'  => (int)    $childId,
                    'product_sku' => (string) $childSku,
                ];
            };

            $products[] = $data;
        }

        return $products;
    }


    /**
     * @param string $sku
     *
     * @return bool|CatalogModelDir\Product
     */
    protected function getProductBySku($sku)
    {
        try {
            /** @var CatalogModelDir\Product $product */
            $product = $this->productRepository->get($sku);
            return $product;
        } catch (\Exception $e) {
            return false;
        }
    }


    /**
     * @param string $sku
     *
     * @return false|int
     */
    protected function getProductIdBySku($sku)
    {
        /** @var CatalogModelDir\ResourceModel\Product $resource */
        $resource  = $this->objectManager()->create(CatalogModelDir\ResourceModel\Product::class);
        $productId = $resource->getIdBySku($sku);

        return $productId;
    }
    
    
    /**
     * @param array $data
     * @param null  $storeId
     *
     * @return CustomerInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     * @throws \Exception
     */
    protected function getCustomer(array $data, $storeId = null)
    {
        $email = $this->arrayExtract($data, 'email');

        try {
            /** @var CustomerInterface $customer */
            $customer = $this->customerRepository->get($email, $this->getStore($storeId)->getWebsiteId());
            $addresses = $customer->getAddresses();
            
            $defaultBilling  = $customer->getDefaultBilling();
            $defaultShipping = $customer->getDefaultShipping();
            
            /** @var AddressInterface $address */
            foreach ($addresses as $address) {
                /** Try to match the billing address. */
                if ($defaultBilling && ($defaultBilling == $address->getId())) {
                    $this->pushAddress($address, self::ADDRESS_TYPE_BILLING);
                    continue;
                }
    
                /** Try to match the shipping address. */
                if ($defaultShipping && ($defaultShipping == $address->getId())) {
                    $this->pushAddress($address, self::ADDRESS_TYPE_SHIPPING);
                    continue;
                }
    
                /** Otherwise use the first for both. */
                $this->pushAddress($address, self::ADDRESS_TYPE_BILLING);
                $this->pushAddress($address, self::ADDRESS_TYPE_SHIPPING);
                
                break;
            }
            
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $customer = $this->createCustomer($data, $storeId);
        } catch (\Exception $e) {
            $this->logger()->critical($e);
            throw $e;
        }

        return $customer;
    }


    /**
     * @param array $data
     * @param array $storeId
     *
     * @return CustomerInterface
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     */
    protected function createCustomer(array $data, $storeId = null)
    {
        $customer = $this->customerFactory->create();
        $customer->setStoreId($this->getStore($storeId)->getId());

        $dateOfBirth = $this->arrayExtract($data, 'date_of_birth');
        $email       = $this->arrayExtract($data, 'email');
        $gender      = $this->arrayExtract($data, 'gender');
        $name        = $this->arrayExtract($data, 'name');
        $phones      = $this->arrayExtract($data, 'phones', []);

        /** @var DataObject $nameObject */
        $nameObject = $this->breakName($name);

        $customer->setFirstname($nameObject->getData('firstname'));
        $customer->setLastname($nameObject->getData('lastname'));
        $customer->setMiddlename($nameObject->getData('middlename'));
        $customer->setEmail($email);
        $customer->setDob($dateOfBirth);

        /** @todo Make this method works after customer attributes mapping logic is created. */
        // $this->setPersonTypeInformation($data, $customer);

        /** @var string $phone */
        foreach ($phones as $phone) {
            $customer->setData('telephone', $phone);
            break;
        }
    
        switch ($gender) {
            case 'male':
                $customer->setGender(1);
                break;
            case 'female':
                $customer->setGender(2);
                break;
        }
        
        $addresses = [];

        /** @var DataObject $billing */
        if ($billing = $this->arrayExtract($data, 'billing_address')) {
            $address = $this->createCustomerAddress($billing, $customer, self::ADDRESS_TYPE_BILLING);
            $addresses[] = $address;
        }

        /** @var DataObject $billing */
        if ($shipping = $this->arrayExtract($data, 'shipping_address')) {
            $address = $this->createCustomerAddress($shipping, $customer, self::ADDRESS_TYPE_SHIPPING);
            $addresses[] = $address;
        }
        
        $customer->setAddresses($this->addresses);
        $customer = $this->customerRepository->save($customer);

        return $customer;
    }
    
    
    /**
     * @param DataObject        $addressObject
     * @param CustomerInterface $customer
     *
     * @return AddressInterface
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function createCustomerAddress(DataObject $addressObject, CustomerInterface $customer, $type)
    {
        /** @var AddressInterface $address */
        $address = $this->addressFactory->create();
    
        $streetLinesCount = (int) $this->helperContext()
            ->scopeConfig()
            ->getValue('customer/address/street_lines');
    
        /**
         * The customer configuration can be set to use 2 fields only.
         */
        $street = $this->prepareAddressStreetLines(
            $addressObject->getData('street'),
            $addressObject->getData('number'),
            $addressObject->getData('neighborhood'),
            $addressObject->getData('complement'),
            $streetLinesCount
        );
        
        $reference = $addressObject->getData('reference');
        $postcode  = $addressObject->getData('postcode');
        $phone     = $addressObject->getData('phone');
        $country   = $addressObject->getData('country');
        $city      = $addressObject->getData('city');
        
        /** @var \Magento\Customer\Api\Data\RegionInterface $region */
        $region = $this->regionFactory->create();
        $region->setRegion($addressObject->getData('region'));
        
        $address->setFirstname($customer->getFirstname())
            ->setLastname($customer->getLastname())
            ->setTelephone($phone)
            ->setStreet($street)
            ->setCity($city)
            ->setRegion($region)
            ->setPostcode($postcode)
            ->setCountryId($country ?: 'BR')
        ;
        
        $this->pushAddress($address, $type);
        
        return $address;
    }
    
    
    /**
     * @param AddressInterface $address
     * @param string           $type
     *
     * @return $this
     */
    protected function pushAddress(AddressInterface $address, $type)
    {
        $this->addresses[$type] = $address;
        return $this;
    }
    
    
    /**
     * @return AddressInterface|mixed
     */
    protected function getBillingAddress()
    {
        /** @todo Create a logic to retrieve this address when address was not created in this process. */
        $address = $this->addresses[self::ADDRESS_TYPE_BILLING];
        
        if (empty($address)) {
            $address = $this->addresses[self::ADDRESS_TYPE_SHIPPING];
        }
        
        return $address;
    }
    
    
    /**
     * @return AddressInterface|mixed
     */
    protected function getShippingAddress()
    {
        /** @todo Create a logic to retrieve this address when address was not created in this process. */
        $address = $this->addresses[self::ADDRESS_TYPE_SHIPPING];
        
        if (empty($address)) {
            $address = $this->addresses[self::ADDRESS_TYPE_BILLING];
        }
        
        return $address;
    }


    /**
     * @param Store $store
     *
     * @return $this
     */
    protected function simulateStore(Store $store)
    {
        $this->storeManager()->setCurrentStore($store);
        return $this;
    }


    /**
     * @return \Magento\Store\Api\Data\StoreInterface
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStore($storeId = null)
    {
        return $this->storeManager()->getStore($storeId);
    }


    /**
     * @param string $code
     *
     * @return int|bool
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getOrderId($skyhubCode)
    {
        /** @var \BitTools\SkyHub\Model\ResourceModel\Order $orderResource */
        $orderResource = $this->objectManager()->create(\BitTools\SkyHub\Model\ResourceModel\Order::class);
        $orderId       = $orderResource->getOrderId($skyhubCode);

        return $orderId;
    }


    /**
     * @param string $code
     *
     * @return string | null
     */
    protected function getOrderIncrementId($code)
    {
        /**
         * @todo Check if this is really necessary.
         */
        $useDefaultIncrementId = $this->getSkyHubModuleConfig('use_default_increment_id', 'cron_sales_order_queue');

        if (!$useDefaultIncrementId) {
            return $code;
        }

        return null;
    }
    
    
    /**
     * @param array    $data
     * @param Customer $customer
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function setPersonTypeInformation(array $data, Customer $customer)
    {
        /**
         * @todo Check this entire method.
         */

        //get the vat number
        $vatNumber = $this->arrayExtract($data, 'vat_number');
        //the taxvat is filled anyway
        $customer->setTaxvat($vatNumber);
        //check if is a PJ customer (if not, it's a PF customer)
        $customerIsPj = $this->customerIsPj($vatNumber);

        //get customer mapped attributes
        $mappedCustomerAttributes = $this->getMappedAttributes();

        //if the store has the attribute "person_type" mapped
        if (isset($mappedCustomerAttributes['person_type'])) {
            $personTypeAttributeId = $mappedCustomerAttributes['person_type']->getAttributeId();
            $personTypeAttribute = $this->getAttributeById($personTypeAttributeId);

            if ($customerIsPj) {
                $personTypeAttributeValue = $this->getAttributeMappingOptionMagentoValue('person_type', 'legal_person');
            } else {
                $personTypeAttributeValue = $this->getAttributeMappingOptionMagentoValue('person_type', 'physical_person');
            }
            $customer->setData($personTypeAttribute->getAttributeCode(), $personTypeAttributeValue);
        }

        if ($customerIsPj) {
            //set the mapped PJ attribute value on customer if exists
            if (isset($mappedCustomerAttributes['cnpj'])) {
                $mappedAttribute = $mappedCustomerAttributes['cnpj'];
                $attribute = $this->getAttributeById($mappedAttribute->getAttributeId());
                $customer->setData($attribute->getAttributeCode(), $vatNumber);
            }
        } else {
            //set the mapped PF attribute value on customer if exists
            if (isset($mappedCustomerAttributes['cpf'])) {
                $mappedAttribute = $mappedCustomerAttributes['cpf'];
                $attribute = $this->getAttributeById($mappedAttribute->getAttributeId());
                $customer->setData($attribute->getAttributeCode(), $vatNumber);
            }
        }

        //set the mapped IE attribute value on customer if exists
        if (isset($mappedCustomerAttributes['ie'])) {
            $mappedAttribute = $mappedCustomerAttributes['ie'];
            $attribute = $this->getAttributeById($mappedAttribute->getAttributeId());
            $customer->setData($attribute->getAttributeCode(), $this->arrayExtract($data, 'state_registration'));
        }

        //set the mapped IE attribute value on customer if exists
        if (isset($mappedCustomerAttributes['social_name'])) {
            $mappedAttribute = $mappedCustomerAttributes['social_name'];
            $attribute = $this->getAttributeById($mappedAttribute->getAttributeId());
            $customer->setData($attribute->getAttributeCode(), $this->arrayExtract($data, 'name'));
        }
    }
}