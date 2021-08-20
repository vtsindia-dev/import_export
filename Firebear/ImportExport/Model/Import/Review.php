<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Import;

use Firebear\ImportExport\Traits\Import\Entity as ImportTrait;
use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Helper\Data;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractEntity;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\ImportExport\Model\ImportFactory;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\Review\Model\RatingFactory;
use Magento\Review\Model\ResourceModel\Rating\CollectionFactory as RatingCollectionFactory;
use Magento\Review\Model\Review as ReviewModel;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Review import
 */
class Review extends AbstractEntity implements ImportAdapterInterface
{
    use ImportTrait;

    /**
     * Sku column name
     */
    const COLUMN_SKU = 'sku';

    /**
     * Review id column name
     */
    const COLUMN_REVIEW_ID = 'review_id';

    /**
     * Created at column name
     */
    const COLUMN_CREATED_AT = 'created_at';

    /**
     * Entity pk value column name
     */
    const COLUMN_ENTITY_PK_VALUE = 'entity_pk_value';

    /**
     * Entity Id column name
     */
    const COLUMN_ENTITY_ID = 'entity_id';

    /**
     * Status column name
     */
    const COLUMN_STATUS = 'status';

    /**
     * Status id column name
     */
    const COLUMN_STATUS_ID = 'status_id';

    /**
     * Stores column name
     */
    const COLUMN_STORES = 'stores';
    /**
     * Error codes
     */
    const ERROR_SKU_NOT_FOUND = 'reviewSkuNotFound';
    const ERROR_REVIEW_ID_IS_EMPTY = 'reviewIdIsEmpty';
    /**
     * Source model
     *
     * @var AbstractSource
     */
    protected $_source;
    /**
     * Source model
     *
     * @var Helper
     */
    protected $resourceHelper;
    /**
     * Json helper
     *
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $jsonHelper;
    /**
     * Import export data
     *
     * @var Data
     */
    protected $importExportData;
    /**
     * Product instance
     *
     * @var \Magento\Catalog\Model\Product
     */
    protected $product;
    /**
     * Reviews factory
     *
     * @var ReviewFactory
     */
    protected $reviewsFactory;
    /**
     * Rating factory
     *
     * @var RatingFactory
     */
    protected $ratingFactory;
    /**
     * Rating collection factory
     *
     * @var RatingCollectionFactory
     */
    protected $ratingCollectionFactory;
    /**
     * Rating fields
     *
     * @var array
     */
    protected $ratingFields;
    /**
     * Rating option fields
     *
     * @var array
     */
    protected $optionFields;
    /**
     * Status map
     *
     * @var array
     */
    protected $statusMap;
    /**
     * Store manager
     *
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * All stores code-ID pairs.
     *
     * @var array
     */
    protected $storeCodeToId = [];
    /**
     * Validation failure message template definitions
     *
     * @var array
     */
    protected $_messageTemplates = [
        self::ERROR_SKU_NOT_FOUND => 'Product with specified SKU not found',
        self::ERROR_REVIEW_ID_IS_EMPTY => 'Review id is empty',
    ];

    /**
     * Permanent entity columns
     *
     * @var string[]
     */
    protected $_permanentAttributes = [
        self::COLUMN_SKU,
        'nickname',
        'title',
        'detail',
        self::COLUMN_STATUS,
    ];

    protected $validColumnNames = [
        self::COLUMN_REVIEW_ID,
        'created_at',
        'vote:Quality',
        'vote:Value',
        'vote:Price',
        'vote:Rating'
    ];

    protected $_availableBehaviors = [
        \Magento\ImportExport\Model\Import::BEHAVIOR_ADD_UPDATE,
        \Magento\ImportExport\Model\Import::BEHAVIOR_DELETE,
        \Magento\ImportExport\Model\Import::BEHAVIOR_REPLACE,
    ];
    /**
     * Initialize import
     *
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param ImportFactory $importFactory
     * @param ProductInterface $product
     * @param ReviewFactory $reviewsFactory
     * @param RatingFactory $ratingFactory
     * @param RatingCollectionFactory $ratingCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        ImportFactory $importFactory,
        ProductInterface $product,
        ReviewFactory $reviewsFactory,
        RatingFactory $ratingFactory,
        RatingCollectionFactory $ratingCollectionFactory,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->_logger = $context->getLogger();
        $this->output = $context->getOutput();
        $this->importExportData = $context->getImportExportData();
        $this->resourceHelper = $context->getResourceHelper();
        $this->jsonHelper = $context->getJsonHelper();
        $this->product = $product;
        $this->reviewsFactory = $reviewsFactory;
        $this->ratingFactory = $ratingFactory;
        $this->ratingCollectionFactory = $ratingCollectionFactory;
        $this->storeManager = $storeManager;

        parent::__construct(
            $context->getStringUtils(),
            $scopeConfig,
            $importFactory,
            $context->getResourceHelper(),
            $context->getResource(),
            $context->getErrorAggregator(),
            $data
        );

        $this->initRating();
        $this->initStatus();
        $this->initStores();
    }

    /**
     * Init rating
     *
     * @return void
     */
    protected function initRating()
    {
        if (null === $this->ratingFields) {
            $collection = $this->ratingCollectionFactory->create()
                ->addEntityFilter(ReviewModel::ENTITY_PRODUCT_CODE);

            foreach ($collection as $rating) {
                $this->ratingFields[$rating->getId()] = $rating->getRatingCode();
                $options = [];
                foreach ($rating->getOptions() as $option) {
                    $options[$option->getValue()] = $option->getId();
                }
                $this->optionFields[$rating->getId()] = $options;
            }
        }
    }

    /**
     * Init rating
     *
     * @return void
     */
    protected function initStatus()
    {
        if (null === $this->statusMap) {
            $collection = $this->reviewsFactory->create()->getStatusCollection();
            foreach ($collection as $status) {
                $this->statusMap[$status->getId()] = $status->getStatusCode();
            }
        }
    }

    /**
     * Initialize stores data
     *
     * @param bool $withDefault
     * @return $this
     */
    protected function initStores($withDefault = false)
    {
        /** @var $store Store */
        foreach ($this->storeManager->getStores($withDefault) as $store) {
            $this->storeCodeToId[$store->getCode()] = $store->getId();
        }
        return $this;
    }

    /**
     * Retrieve All Fields Source
     *
     * @return array
     */
    public function getAllFields()
    {
        $options = array_merge($this->getValidColumnNames());
        $options = array_merge($options, $this->_permanentAttributes);

        return array_unique($options);
    }

    /**
     * Import data rows
     *
     * @return boolean
     * @throws \Exception
     */
    protected function _importData()
    {
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            foreach ($bunch as $rowNumber => $rowData) {
                /* validate data */
                if (!$rowData || !$this->validateRow($rowData, $rowNumber)) {
                    continue;
                }

                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNumber);
                    continue;
                }

                /* behavior selector */
                switch ($this->getBehavior()) {
                    case Import::BEHAVIOR_DELETE:
                        $this->delete($rowData);
                        break;
                    case Import::BEHAVIOR_REPLACE:
                        if ($this->isReviewId($rowData)) {
                            $this->save($rowData);
                        }
                        break;
                    case Import::BEHAVIOR_ADD_UPDATE:
                        $this->save($rowData);
                        break;
                }
            }
        }
        return true;
    }

    /**
     * Validate data row
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return bool
     */
    public function validateRow(array $rowData, $rowNumber)
    {
        if (isset($this->_validatedRows[$rowNumber])) {
            /* check that row is already validated */
            return !$this->getErrorAggregator()->isRowInvalid($rowNumber);
        }

        $this->_validatedRows[$rowNumber] = true;
        $this->_processedEntitiesCount++;

        /* behavior selector */
        switch ($this->getBehavior()) {
            case Import::BEHAVIOR_DELETE:
                $this->validateRowForDelete($rowData, $rowNumber);
                break;
            case Import::BEHAVIOR_REPLACE:
                $this->validateRowForReplace($rowData, $rowNumber);
                break;
            case Import::BEHAVIOR_ADD_UPDATE:
                $this->validateRowForUpdate($rowData, $rowNumber);
                break;
        }
        return !$this->getErrorAggregator()->isRowInvalid($rowNumber);
    }

    /**
     * Validate row data for delete behaviour
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return void
     */
    public function validateRowForDelete(array $rowData, $rowNumber)
    {
        if (empty($rowData[self::COLUMN_REVIEW_ID])) {
            $this->addRowError(self::ERROR_REVIEW_ID_IS_EMPTY, $rowNumber);
        }
    }

    /**
     * Validate row data for replace behaviour
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return void
     */
    public function validateRowForReplace(array $rowData, $rowNumber)
    {
        $this->validateRowForDelete($rowData, $rowNumber);
        $this->validateRowForUpdate($rowData, $rowNumber);
    }

    /**
     * Validate row data for update behaviour
     *
     * @param array $rowData
     * @param int $rowNumber
     * @return void
     */
    public function validateRowForUpdate(array $rowData, $rowNumber)
    {
        if (!empty($rowData[self::COLUMN_SKU])) {
            $pkValue = $this->product->getIdBySku($rowData[self::COLUMN_SKU]);
            if (!$pkValue) {
                $this->addRowError(self::ERROR_SKU_NOT_FOUND, $rowNumber);
            }
        }
    }

    /**
     * Delete row
     *
     * @param array $rowData
     * @return $this
     * @throws \Exception
     */
    protected function delete(array $rowData)
    {
        $review = $this->reviewsFactory->create();
        $review->load($rowData[self::COLUMN_REVIEW_ID]);

        if ($review->getId()) {
            $review->delete();
            $this->countItemsDeleted++;
        }
        return $this;
    }

    /**
     * Update entity
     *
     * @param array $rowData
     * @return $this
     * @throws \Exception
     */
    public function save(array $rowData)
    {
        $review = $this->reviewsFactory->create();
        if (!empty($rowData[self::COLUMN_REVIEW_ID])) {
            $review->load($rowData[self::COLUMN_REVIEW_ID]);
        }

        if ($review->getId()) {
            $this->countItemsUpdated++;
        } else {
            $this->countItemsCreated++;
            $rowData[self::COLUMN_REVIEW_ID] = null;
        }

        if (!empty($rowData[self::COLUMN_SKU])) {
            $pkValue = $this->product->getIdBySku($rowData[self::COLUMN_SKU]);
            if ($pkValue) {
                $rowData[self::COLUMN_ENTITY_PK_VALUE] = $pkValue;
            }
        }

        if (empty($rowData[self::COLUMN_ENTITY_ID])) {
            $rowData[self::COLUMN_ENTITY_ID] = $review->getEntityIdByCode(
                ReviewModel::ENTITY_PRODUCT_CODE
            );
        }

        $rating = [];
        foreach ($rowData as $field => $value) {
            if (false === strpos($field, ':')) {
                continue;
            }
            unset($rowData[$field]);
            list($fieldPrefix, $ratingCode) = explode(':', $field);
            if ($fieldPrefix == 'vote') {
                $ratingId = array_search($ratingCode, $this->ratingFields);
                if (false === $ratingId || '' === $value || !isset($this->optionFields[$ratingId][$value])) {
                    continue;
                }
                $rating[$ratingId] = $this->optionFields[$ratingId][$value];
            }
        }
        $rowData['ratings'] = $rating;

        if (!empty($rowData[self::COLUMN_STATUS])) {
            $statusId = array_search($rowData[self::COLUMN_STATUS], $this->statusMap);
            if (false === $statusId) {
                $statusId = ReviewModel::STATUS_PENDING;
            }
            $rowData[self::COLUMN_STATUS_ID] = $statusId;
        }

        if (!empty($rowData[self::COLUMN_STORES])) {
            $rowData[self::COLUMN_STORES] = explode(',', $rowData[self::COLUMN_STORES]);
        } else {
            $rowData[self::COLUMN_STORES] = array_values($this->storeCodeToId);
        }

        unset(
            $rowData[self::COLUMN_SKU],
            $rowData[self::COLUMN_STATUS]
        );
        $review->addData($rowData);
        $review->save();
        $this->updateDateFromDump($review->getId(), $rowData);

        foreach ($rating as $ratingId => $optionId) {
            $this->ratingFactory->create()
                ->setRatingId($ratingId)
                ->setReviewId($review->getId())
                ->addOptionVote($optionId, $rowData[self::COLUMN_ENTITY_PK_VALUE]);
        }
        $review->aggregate();
        return $this;
    }

    /**
     * Save Validated Bunches
     *
     * @return $this
     * @throws LocalizedException
     */
    protected function _saveValidatedBunches()
    {
        $source = $this->_getSource();
        $currentDataSize = 0;
        $bunchRows = [];
        $startNewBunch = false;
        $nextRowBackup = [];
        $maxDataSize = $this->resourceHelper->getMaxDataSize();
        $bunchSize = $this->importExportData->getBunchSize();

        $source->rewind();
        $this->_dataSourceModel->cleanBunches();
        $file = null;
        $jobId = null;
        if (isset($this->_parameters['file'])) {
            $file = $this->_parameters['file'];
        }
        if (isset($this->_parameters['job_id'])) {
            $jobId = $this->_parameters['job_id'];
        }

        while ($source->valid() || $bunchRows) {
            if ($startNewBunch || !$source->valid()) {
                $this->_dataSourceModel->saveBunches(
                    $this->getEntityTypeCode(),
                    $this->getBehavior(),
                    $jobId,
                    $file,
                    $bunchRows
                );
                $bunchRows = $nextRowBackup;
                $currentDataSize = strlen($this->jsonHelper->jsonEncode($bunchRows));
                $startNewBunch = false;
                $nextRowBackup = [];
            }

            if ($source->valid()) {
                try {
                    $rowData = $source->current();
                } catch (InvalidArgumentException $e) {
                    $this->addRowError($e->getMessage(), $this->_processedRowsCount);
                    $this->_processedRowsCount++;
                    $source->next();
                    continue;
                }
                $rowData = $this->customBunchesData($rowData);
                $rowData = $this->validateCreatedAt($rowData, $source->key());
                $this->_processedRowsCount++;
                if ($this->validateRow($rowData, $source->key())) {
                    $rowSize = strlen($this->jsonHelper->jsonEncode($rowData));

                    $isBunchSizeExceeded = $bunchSize > 0 && count($bunchRows) >= $bunchSize;

                    if ($currentDataSize + $rowSize >= $maxDataSize || $isBunchSizeExceeded) {
                        $startNewBunch = true;
                        $nextRowBackup = [$source->key() => $rowData];
                    } else {
                        $bunchRows[$source->key()] = $rowData;
                        $currentDataSize += $rowSize;
                    }
                }
                $source->next();
            }
        }
        return $this;
    }

    /**
     * @param $rowData
     * @param $rowNum
     * @return mixed
     */
    protected function validateCreatedAt($rowData, $rowNum)
    {
        $createdAt = $rowData[self::COLUMN_CREATED_AT] ?? false;
        if ($createdAt) {
            $format = 'Y-m-d H:i:s';
            $date = \DateTime::createFromFormat($format, $createdAt);
            if ($date) {
                $rowData[self::COLUMN_CREATED_AT] = $date->format($format);
            } else {
                $message = 'review with review_id: %1 not imported because the created_at is not correct.';
                $this->addLogWriteln(__($message, $rowData[self::COLUMN_REVIEW_ID]), $this->output, 'error');
                $this->addRowError(__($message, $rowData[self::COLUMN_REVIEW_ID]), $rowNum);
            }
        }

        return $rowData;
    }

    /**
     * @param $reviewId
     * @param $rowData
     */
    protected function updateDateFromDump($reviewId, $rowData)
    {
        if ($reviewId) {
            $this->_connection->insertOnDuplicate(
                $this->_connection->getTableName('review'),
                [self::COLUMN_REVIEW_ID => $reviewId, self::COLUMN_CREATED_AT => $rowData[self::COLUMN_CREATED_AT]],
                [self::COLUMN_CREATED_AT]
            );
        }
    }

    /**
     * @param array $rowData
     * @return bool
     */
    protected function isReviewId(array $rowData)
    {
        $review = $this->reviewsFactory->create();
        $review->load($rowData[self::COLUMN_REVIEW_ID]);
        if ($review->getId()) {
            $result = true;
        } else {
            $result = false;
        }
        return $result;
    }

    /**
     * Inner source object getter
     *
     * @return AbstractSource
     * @throws LocalizedException
     */
    protected function _getSource()
    {
        if (!$this->_source) {
            throw new LocalizedException(__('Please specify a source.'));
        }
        return $this->_source;
    }

    /**
     * Imported entity type code getter
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'review';
    }
}
