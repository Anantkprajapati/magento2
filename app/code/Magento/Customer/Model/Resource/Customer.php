<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Model\Resource;

use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Framework\Exception\AlreadyExistsException;

/**
 * Customer entity resource model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Customer extends \Magento\Eav\Model\Entity\AbstractEntity
{
    /**
     * @var \Magento\Framework\Validator\Factory
     */
    protected $_validatorFactory;

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var \Magento\Framework\Stdlib\DateTime
     */
    protected $dateTime;

    /**
     * @param \Magento\Eav\Model\Entity\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\Validator\Factory $validatorFactory
     * @param \Magento\Framework\Stdlib\DateTime $dateTime
     * @param array $data
     */
    public function __construct(
        \Magento\Eav\Model\Entity\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Validator\Factory $validatorFactory,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        $data = []
    ) {
        parent::__construct($context, $data);
        $this->_scopeConfig = $scopeConfig;
        $this->_validatorFactory = $validatorFactory;
        $this->dateTime = $dateTime;
        $this->setType('customer');
        $this->setConnection('customer_read', 'customer_write');
    }

    /**
     * Retrieve customer entity default attributes
     *
     * @return string[]
     */
    protected function _getDefaultAttributes()
    {
        return [
            'created_at',
            'updated_at',
            'increment_id',
            'store_id',
            'website_id'
        ];
    }

    /**
     * Check customer scope, email and confirmation key before saving
     *
     * @param \Magento\Framework\Object $customer
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _beforeSave(\Magento\Framework\Object $customer)
    {
        /** @var \Magento\Customer\Model\Customer $customer */
        parent::_beforeSave($customer);

        if (!$customer->getEmail()) {
            throw new ValidatorException(__('Customer email is required'));
        }

        $adapter = $this->_getWriteAdapter();
        $bind = ['email' => $customer->getEmail()];

        $select = $adapter->select()->from(
            $this->getEntityTable(),
            [$this->getEntityIdField()]
        )->where(
            'email = :email'
        );
        if ($customer->getSharingConfig()->isWebsiteScope()) {
            $bind['website_id'] = (int)$customer->getWebsiteId();
            $select->where('website_id = :website_id');
        }
        if ($customer->getId()) {
            $bind['entity_id'] = (int)$customer->getId();
            $select->where('entity_id != :entity_id');
        }

        $result = $adapter->fetchOne($select, $bind);
        if ($result) {
            throw new AlreadyExistsException(
                __('Customer with the same email already exists in associated website.')
            );
        }

        // set confirmation key logic
        if ($customer->getForceConfirmed()) {
            $customer->setConfirmation(null);
        } elseif (!$customer->getId() && $customer->isConfirmationRequired()) {
            $customer->setConfirmation($customer->getRandomConfirmationKey());
        }
        // remove customer confirmation key from database, if empty
        if (!$customer->getConfirmation()) {
            $customer->setConfirmation(null);
        }

        $this->_validate($customer);

        return $this;
    }

    /**
     * Validate customer entity
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @return void
     * @throws \Magento\Framework\Validator\Exception
     */
    protected function _validate($customer)
    {
        $validator = $this->_validatorFactory->createValidator('customer', 'save');

        if (!$validator->isValid($customer)) {
            throw new ValidatorException(
                null,
                null,
                $validator->getMessages()
            );
        }
    }

    /**
     * Save customer addresses and set default addresses in attributes backend
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @return $this
     */
    protected function _afterSave(\Magento\Framework\Object $customer)
    {
        $this->_saveAddresses($customer);
        return parent::_afterSave($customer);
    }

    /**
     * Save/delete customer address
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function _saveAddresses(\Magento\Customer\Model\Customer $customer)
    {
        $defaultBillingId = $customer->getData('default_billing');
        $defaultShippingId = $customer->getData('default_shipping');
        /** @var \Magento\Customer\Model\Address $address */
        foreach ($customer->getAddresses() as $address) {
            if ($address->getData('_deleted')) {
                if ($address->getId() == $defaultBillingId) {
                    $customer->setData('default_billing', null);
                }
                if ($address->getId() == $defaultShippingId) {
                    $customer->setData('default_shipping', null);
                }
                $removedAddressId = $address->getId();
                $address->delete();
                // Remove deleted address from customer address collection
                $customer->getAddressesCollection()->removeItemByKey($removedAddressId);
            } else {
                $address->setParentId(
                    $customer->getId()
                )->setStoreId(
                    $customer->getStoreId()
                )->setIsCustomerSaveTransaction(
                    true
                )->save();
                if (($address->getIsPrimaryBilling() ||
                    $address->getIsDefaultBilling()) && $address->getId() != $defaultBillingId
                ) {
                    $customer->setData('default_billing', $address->getId());
                }
                if (($address->getIsPrimaryShipping() ||
                    $address->getIsDefaultShipping()) && $address->getId() != $defaultShippingId
                ) {
                    $customer->setData('default_shipping', $address->getId());
                }
            }
        }
        //@TODO: find appropriate place
        $changedAddresses = [];
        if ($defaultBillingId != $customer->getData('default_billing')) {
            $changedAddresses['default_billing'] = $customer->getData('default_billing');
        }
        if ($defaultShippingId != $customer->getData('default_shipping')) {
            $changedAddresses['default_shipping'] = $customer->getData('default_shipping');
        }
        if ($changedAddresses) {
            $this->_getWriteAdapter()->update(
                $this->getTable('customer_entity'),
                $changedAddresses,
                $this->_getWriteAdapter()->quoteInto('entity_id = ?', $customer->getId())
            );
        }

        return $this;
    }

    /**
     * Retrieve select object for loading base entity row
     *
     * @param \Magento\Framework\Object $object
     * @param string|int $rowId
     * @return \Magento\Framework\DB\Select
     */
    protected function _getLoadRowSelect($object, $rowId)
    {
        $select = parent::_getLoadRowSelect($object, $rowId);
        if ($object->getWebsiteId() && $object->getSharingConfig()->isWebsiteScope()) {
            $select->where('website_id =?', (int)$object->getWebsiteId());
        }

        return $select;
    }

    /**
     * Load customer by email
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @param string $email
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function loadByEmail(\Magento\Customer\Model\Customer $customer, $email)
    {
        $adapter = $this->_getReadAdapter();
        $bind = ['customer_email' => $email];
        $select = $adapter->select()->from(
            $this->getEntityTable(),
            [$this->getEntityIdField()]
        )->where(
            'email = :customer_email'
        );

        if ($customer->getSharingConfig()->isWebsiteScope()) {
            if (!$customer->hasData('website_id')) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Customer website ID must be specified when using the website scope')
                );
            }
            $bind['website_id'] = (int)$customer->getWebsiteId();
            $select->where('website_id = :website_id');
        }

        $customerId = $adapter->fetchOne($select, $bind);
        if ($customerId) {
            $this->load($customer, $customerId);
        } else {
            $customer->setData([]);
        }

        return $this;
    }

    /**
     * Change customer password
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @param string $newPassword
     * @return $this
     */
    public function changePassword(\Magento\Customer\Model\Customer $customer, $newPassword)
    {
        $customer->setPassword($newPassword);
        return $this;
    }

    /**
     * Check whether there are email duplicates of customers in global scope
     *
     * @return bool
     */
    public function findEmailDuplicates()
    {
        $adapter = $this->_getReadAdapter();
        $select = $adapter->select()->from(
            $this->getTable('customer_entity'),
            ['email', 'cnt' => 'COUNT(*)']
        )->group(
            'email'
        )->order(
            'cnt DESC'
        )->limit(
            1
        );
        $lookup = $adapter->fetchRow($select);
        if (empty($lookup)) {
            return false;
        }
        return $lookup['cnt'] > 1;
    }

    /**
     * Check customer by id
     *
     * @param int $customerId
     * @return bool
     */
    public function checkCustomerId($customerId)
    {
        $adapter = $this->_getReadAdapter();
        $bind = ['entity_id' => (int)$customerId];
        $select = $adapter->select()->from(
            $this->getTable('customer_entity'),
            'entity_id'
        )->where(
            'entity_id = :entity_id'
        )->limit(
            1
        );

        $result = $adapter->fetchOne($select, $bind);
        if ($result) {
            return true;
        }
        return false;
    }

    /**
     * Get customer website id
     *
     * @param int $customerId
     * @return int
     */
    public function getWebsiteId($customerId)
    {
        $adapter = $this->_getReadAdapter();
        $bind = ['entity_id' => (int)$customerId];
        $select = $adapter->select()->from(
            $this->getTable('customer_entity'),
            'website_id'
        )->where(
            'entity_id = :entity_id'
        );

        return $adapter->fetchOne($select, $bind);
    }

    /**
     * Custom setter of increment ID if its needed
     *
     * @param \Magento\Framework\Object $object
     * @return $this
     */
    public function setNewIncrementId(\Magento\Framework\Object $object)
    {
        if ($this->_scopeConfig->getValue(
            \Magento\Customer\Model\Customer::XML_PATH_GENERATE_HUMAN_FRIENDLY_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        )
        ) {
            parent::setNewIncrementId($object);
        }
        return $this;
    }

    /**
     * Change reset password link token
     *
     * Stores new reset password link token and its creation time
     *
     * @param \Magento\Customer\Model\Customer $customer
     * @param string $passwordLinkToken
     * @return $this
     */
    public function changeResetPasswordLinkToken(\Magento\Customer\Model\Customer $customer, $passwordLinkToken)
    {
        if (is_string($passwordLinkToken) && !empty($passwordLinkToken)) {
            $customer->setRpToken($passwordLinkToken);
            $customer->setRpTokenCreatedAt(
                (new \DateTime())->format(\Magento\Framework\Stdlib\DateTime::DATETIME_PHP_FORMAT)
            );
        }
        return $this;
    }
}
