<?php
/**
 * @copyright: Copyright © 2017 Firebear Studio. All rights reserved.
 * @author   : Firebear Studio <fbeardev@gmail.com>
 */

namespace Firebear\ImportExport\Model\Export;

use DateTime;
use Exception;
use Firebear\ImportExport\Traits\Export\Entity as ExportTrait;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\ImportExport\Model\Export\AbstractEntity;
use Magento\ImportExport\Model\Export\Factory as ExportFactory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection as OrderStatusCollection;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Psr\Log\LoggerInterface;
use Firebear\ImportExport\Model\Export\Dependencies\Config as ExportConfig;
use Firebear\ImportExport\Model\Source\Factory as SourceFactory;
use Firebear\ImportExport\Model\ExportJob\Processor;
use Firebear\ImportExport\Helper\Data as Helper;
use Zend_Db_Statement_Exception;

/**
 * Order export adapter
 *
 * @package Firebear\ImportExport\Model\Export
 */
class Order extends AbstractEntity implements EntityInterface
{
    use ExportTrait;

    /**
     * Orders whose data is exported
     *
     * @var OrderCollection
     */
    protected $_orderCollection;

    /**
     * Resource Model
     *
     * @var ResourceConnection
     */
    protected $_resourceModel;

    /**
     * DB connection
     *
     * @var AdapterInterface
     */
    protected $_connection;

    /**
     * Header columns
     *
     * @var array
     */
    protected $_headerColumns = [];

    /**
     * Item export data
     *
     * @var array
     */
    protected $_exportData = [];

    /**
     * Validate filters result
     *
     * @var array
     */
    protected $filters = [];

    /**
     * @var array
     */
    protected $_fieldsMap = [];

    /**
     * Export config data
     *
     * @var array
     */
    protected $_exportConfig;

    /**
     * @var SourceFactory
     */
    protected $_sourceFactory;

    /**
     * @var Helper
     */
    protected $_helper;

    /**
     * Describe table
     *
     * @var array
     */
    protected $_describeTable = [];

    /**
     * Prefix data
     *
     * @var array
     */
    protected $_prefixData = [
        'sales_order_item' => 'item',
        'sales_order_address' => 'address',
        'sales_order_payment' => 'payment',
        'sales_payment_transaction' => 'transaction',
        'sales_shipment' => 'shipment',
        'sales_shipment_item' => 'shipment_item',
        'sales_shipment_comment' => 'shipment_comment',
        'sales_shipment_track' => 'shipment_track',
        'sales_invoice' => 'invoice',
        'sales_invoice_item' => 'invoice_item',
        'sales_invoice_comment' => 'invoice_comment',
        'sales_creditmemo' => 'creditmemo',
        'sales_creditmemo_item' => 'creditmemo_item',
        'sales_creditmemo_comment' => 'creditmemo_comment',
        'sales_order_status_history' => 'status_history',
        'sales_order_tax' => 'tax',
        'sales_order_tax_item' => 'tax_item',
    ];

    /**
     * Default values
     *
     * @var array
     */
    protected $_default = [];

    /**
     * Order Status Collection
     *
     * @var OrderStatusCollection
     */
    protected $_statusCollection;

    /**
     * Order Statuses Label
     *
     * @var array
     */
    protected $_status;

    /**
     * Initialize export
     *
     * @param LoggerInterface $logger
     * @param ConsoleOutput $output
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ExportFactory $collectionFactory
     * @param CollectionByPagesIteratorFactory $resourceColFactory
     * @param OrderCollectionFactory $orderColFactory
     * @param ResourceConnection $resource
     * @param ExportConfig $exportConfig
     * @param SourceFactory $sourceFactory
     * @param Helper $helper
     * @param StatusCollectionFactory $statusCollectionFactory
     * @param array $data
     */
    public function __construct(
        LoggerInterface $logger,
        ConsoleOutput $output,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ExportFactory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        OrderCollectionFactory $orderColFactory,
        ResourceConnection $resource,
        ExportConfig $exportConfig,
        SourceFactory $sourceFactory,
        Helper $helper,
        StatusCollectionFactory $statusCollectionFactory,
        array $data = []
    ) {
        $this->_logger = $logger;
        $this->output = $output;
        $this->_resourceModel = $resource;
        $this->_exportConfig = $exportConfig->get();
        $this->_sourceFactory = $sourceFactory;
        $this->_helper = $helper;
        $this->_statusCollection = $statusCollectionFactory->create();
        $this->_orderCollection = $data['order_collection'] ?? $orderColFactory->create();
        $this->_connection = $resource->getConnection();

        parent::__construct(
            $scopeConfig,
            $storeManager,
            $collectionFactory,
            $resourceColFactory
        );

        $this->_initStores();
    }

    /**
     * Retrieve entity type code
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return 'order';
    }

    /**
     * Retrieve adapter name
     *
     * @return string
     */
    public function getName()
    {
        return __('Orders');
    }

    /**
     * Retrieve header columns
     *
     * @return array
     */
    public function _getHeaderColumns()
    {
        return $this->_customHeadersMapping(
            $this->_headerColumns
        );
    }

    /**
     * Retrieve orders collection
     *
     * @return OrderCollection
     */
    protected function _getEntityCollection()
    {
        return $this->_orderCollection;
    }

    /**
     * Retrieve order statuses
     *
     * @param string $status
     * @return string
     */
    protected function _getStatusLabel($status)
    {
        if (null === $this->_status) {
            $this->_status = [];
            foreach ($this->_statusCollection as $item) {
                $this->_status[$item->getStatus()] = $item->getLabel();
            }
        }
        return isset($this->_status[$status])
            ? $this->_status[$status]
            : '';
    }

    /**
     * Export process
     *
     * @return array
     * @throws LocalizedException
     * @throws Exception
     */
    public function export()
    {
        //Execution time may be very long
        set_time_limit(0);
        if (!isset($this->_parameters['behavior_data']['deps'])) {
            throw new LocalizedException(__('You have not selected items.'));
        }
        $this->addLogWriteln(__('Begin Export'), $this->output);
        $this->addLogWriteln(__('Scope Data'), $this->output);

        $collection = $this->_getEntityCollection();
        $this->_prepareEntityCollection($collection);
        $this->_exportCollectionByPages($collection);
        // create export file
        return [
            $this->getWriter()->getContents(),
            $this->_processedEntitiesCount,
            $this->lastEntityId
        ];
    }

    /**
     * Export one item
     *
     * @param AbstractModel $item
     * @return void
     * @throws LocalizedException
     * @throws Zend_Db_Statement_Exception
     */
    public function exportItem($item)
    {
        $exportData = $this->_getExportData($item);
        /* skip order if at least one child entity is not valid */
        foreach ($this->filters as $table => $result) {
            /* check valid of child table (exclude sales_order) */
            if (false === $result && $table != 'sales_order') {
                return;
            }
            if (is_array($result)) {
                foreach ($result as $field => $isValid) {
                    if (false === $isValid) {
                        return;
                    }
                }
            }
        }
        foreach ($exportData as $row) {
            $this->getWriter()->writeRow($row);
        }
        $this->_processedEntitiesCount++;
    }

    /**
     * Get export data for collection
     *
     * @param AbstractModel $item
     * @return array
     * @throws Zend_Db_Statement_Exception
     * @throws Exception
     */
    protected function _getExportData($item)
    {
        $orderId = $item->getId();
        $deps = $this->_parameters['behavior_data']['deps'];
        $children = $this->_exportConfig['order']['fields'] ?? [];
        $this->lastEntityId = $orderId;
        $this->filters = [];

        if (!count($this->_default) && !$this->_isNested()) {
            $tables = array_keys($this->_prefixData);
            foreach ($tables as $table) {
                if (!in_array($table, $deps)) {
                    continue;
                }
                if (empty($this->_describeTable[$table])) {
                    $this->_describeTable[$table] = $this->_connection->describeTable(
                        $this->_resourceModel->getTableName($table)
                    );
                }
                $prefix = $this->_prefixData[$table] ?? $table;
                $row = [];
                foreach (array_keys($this->_describeTable[$table]) as $column) {
                    $row[$prefix . ':' . $column] = '';
                }
                $row = $this->_updateData($row, $table);
                $this->_default = array_merge($this->_default, $row);
            }
        }

        $exportData = $item->toArray();
        unset($exportData['store_name']);

        $exportData['status_label'] = isset($exportData['status'])
            ? $this->_getStatusLabel($exportData['status'])
            : '';

        $exportData = $this->_updateData($exportData, 'sales_order');
        $this->_exportData = [0 => array_merge($exportData, $this->_default)];

        foreach ($children as $table => $param) {
            if ($param['parent'] == 'sales_order' && in_array($table, $deps)) {
                $this->_prepareChildEntity([$orderId], $table, $param['parent_field'], $param['main_field']);
            }
        }
        return $this->_exportData;
    }

    protected function _isNested()
    {
        return in_array(
            $this->_parameters['behavior_data']['file_format'],
            ['xml', 'json']
        );
    }

    /**
     * Prepare child entity
     *
     * @param array  $entityIds
     * @param string $table
     * @param int $parentIdField
     * @param int $entityIdField
     * @return void
     * @throws Zend_Db_Statement_Exception
     * @throws Exception
     */
    protected function _prepareChildEntity($entityIds, $table, $parentIdField, $entityIdField)
    {
        $rowId = 0;
        $select = $this->_connection->select()->from(
            $this->_resourceModel->getTableName($table)
        )->where(
            $parentIdField . ' IN (?)',
            $entityIds
        );
        $stmt = $this->_connection->query($select);
        $prefix = $this->_prefixData[$table] ?? $table;

        $deps = $this->_parameters['behavior_data']['deps'];
        $children = $this->_exportConfig['order']['fields'] ?? [];
        $entityIds = [];

        if ($this->_isNested()) {
            $exportData = [];
            while ($row = $stmt->fetch()) {
                $entityIds[] = $row[$entityIdField];
                $exportData[] = ['item' => $this->_updateData($row, $table)];
            }
            $this->_exportData[0][$prefix] = $exportData;
        } else {
            while ($row = $stmt->fetch()) {
                $entityIds[] = $row[$entityIdField];
                foreach ($row as $column => $value) {
                    $row[$prefix . ':' . $column] = $value;
                    unset($row[$column]);
                }
                $row = $this->_updateData($row, $table);
                $exportData = $this->_exportData[$rowId] ?? [];
                $this->_exportData[$rowId] = array_merge($exportData, $row);
                $rowId++;
            }
        }

        if (!count($entityIds)) {
            if (!isset($this->_parameters[Processor::EXPORT_FILTER_TABLE]) ||
                !is_array($this->_parameters[Processor::EXPORT_FILTER_TABLE])) {
                $exportFilter = [];
            } else {
                $exportFilter = $this->_parameters[Processor::EXPORT_FILTER_TABLE];
            }

            foreach ($exportFilter as $filter) {
                if ($filter['entity'] == $table) {
                    $this->filters[$table] = false;
                } else {
                    foreach ($children as $childTable => $param) {
                        if ($filter['entity'] == $childTable && $param['parent'] == $table) {
                            $this->filters[$childTable] = false;
                        }
                    }
                }
            }
            return;
        }

        foreach ($children as $childTable => $param) {
            if ($param['parent'] == $table && in_array($table, $deps)) {
                $this->_prepareChildEntity($entityIds, $childTable, $param['parent_field'], $param['main_field']);
            }
        }
    }

    /**
     * Retrieve headers mapping
     *
     * @param array $rowData
     * @return array
     */
    protected function _customHeadersMapping($rowData)
    {
        foreach ($rowData as $key => $fieldName) {
            if (isset($this->_fieldsMap[$fieldName])) {
                $rowData[$key] = $this->_fieldsMap[$fieldName];
            }
        }
        return $rowData;
    }

    /**
     * Apply filter to collection
     *
     * @param AbstractCollection $collection
     * @return AbstractCollection
     * @throws Exception
     */
    protected function _prepareEntityCollection(AbstractCollection $collection)
    {
        if (isset($this->_parameters['last_entity_id']) &&
            $this->_parameters['last_entity_id'] > 0 &&
            $this->_parameters['enable_last_entity_id'] > 0
        ) {
            $collection->addFieldToFilter(
                'entity_id',
                ['gt' => $this->_parameters['last_entity_id']]
            );
        }

        if (!isset($this->_parameters[Processor::EXPORT_FILTER_TABLE]) ||
            !is_array($this->_parameters[Processor::EXPORT_FILTER_TABLE])) {
            $exportFilter = [];
        } else {
            $exportFilter = $this->_parameters[Processor::EXPORT_FILTER_TABLE];
        }

        $filters = [];
        $entity = 'sales_order';
        foreach ($exportFilter as $data) {
            if ($data['entity'] == $entity) {
                $filters[$data['field']] = $data['value'];
            }
        }

        $fields = $this->getTableColumns();
        foreach ($filters as $key => $value) {
            if (isset($fields[$entity][$key])) {
                $type = $fields[$entity][$key]['type'];
                if ('text' == $type) {
                    if (is_scalar($value)) {
                        trim($value);
                    }
                    $collection->addFieldToFilter($key, ['like' => "%{$value}%"]);
                } elseif ('int' == $type) {
                    if (is_array($value) && count($value) == 2) {
                        $from = array_shift($value);
                        $to = array_shift($value);
                        if (is_numeric($from)) {
                            $collection->addFieldToFilter($key, ['from' => $from]);
                        }
                        if (is_numeric($to)) {
                            $collection->addFieldToFilter($key, ['to' => $to]);
                        }
                    } else {
                        $collection->addFieldToFilter($key, $value);
                    }
                } elseif ('date' == $type) {
                    if (is_array($value) && count($value) == 2) {
                        $from = array_shift($value);
                        $to = array_shift($value);

                        if ($from == 'NaN') {
                            $from = '';
                        }
                        if ($to == 'NaN') {
                            $to = '';
                        }

                        if (is_scalar($from) && !empty($from)) {
                            $date = (new DateTime($from))->format('m/d/Y');
                            $collection->addFieldToFilter($key, ['from' => $date, 'date' => true]);
                        }
                        if (is_scalar($to) && !empty($to)) {
                            $date = (new DateTime($to))->format('m/d/Y');
                            $collection->addFieldToFilter($key, ['to' => $date, 'date' => true]);
                        }
                    }
                }
            }
        }
        return $collection;
    }

    /**
     * Retrieve entity field for export
     *
     * @return array
     * @throws LocalizedException
     */
    public function getFieldsForExport()
    {
        $options = [];
        foreach ($this->_exportConfig as $typeName => $type) {
            if ($typeName == 'order') {
                foreach ($type['fields'] as $name => $values) {
                    $prefix = $this->_prefixData[$name] ?? '';
                    if ($prefix) {
                        $prefix .= ':';
                    }
                    $model = $this->_sourceFactory->create($values['model']);
                    $options[$name] = [
                        'label' => __($values['label']),
                        'optgroup-name' => $name,
                        'value' => []
                    ];
                    $fields = $this->getChildHeaders($model);
                    foreach ($fields as $field) {
                        $options[$name]['value'][] = [
                            'label' => $field,
                            'value' => $prefix . $field
                        ];
                    }
                }
            }
        }
        return $options;
    }

    /**
     * Retrieve entity field for filter
     *
     * @return array
     * @throws LocalizedException
     */
    public function getFieldsForFilter()
    {
        $options = [];
        foreach ($this->_exportConfig as $typeName => $type) {
            if ($typeName == 'order') {
                foreach ($type['fields'] as $name => $values) {
                    $model = $this->_sourceFactory->create($values['model']);
                    $fields = $this->getChildHeaders($model);
                    $mergeFields = [];
                    if (isset($values['fields'])) {
                        $mergeFields = $values['fields'];
                    }
                    foreach ($fields as $field) {
                        if (isset($mergeFields[$field]) && $mergeFields[$field]['delete']) {
                            continue;
                        }
                        $options[$name][] = [
                            'label' => $field,
                            'value' => $field
                        ];
                    }
                }
            }
        }
        return $options;
    }

    /**
     * @return array
     * @throws LocalizedException
     */
    protected function getTableColumns()
    {
        $options = [];
        foreach ($this->_exportConfig as $typeName => $type) {
            if ($typeName == 'catalog_category' || !isset($type['fields']) || !isset($type['fields'])) {
                continue;
            }
            foreach ($type['fields'] as $name => $values) {
                $model = $this->_sourceFactory->create($values['model']);
                $fields = $this->describeTable($model);
                foreach ($fields as $key => $field) {
                    $type = $this->_helper->convertTypesTables($field['DATA_TYPE']);
                    $options[$name][$key] = ['type' => $type];
                }
            }
        }
        return $options;
    }

    /**
     * @return array
     * @throws LocalizedException
     */
    public function getFieldColumns()
    {
        $options = [];
        foreach ($this->_exportConfig as $typeName => $type) {
            if ($typeName == 'order') {
                foreach ($type['fields'] as $name => $values) {
                    $mergeFields = [];
                    if (isset($values['fields'])) {
                        $mergeFields = $values['fields'];
                    }
                    $model = $this->_sourceFactory->create($values['model']);
                    $fields = $this->describeTable($model);
                    foreach ($fields as $key => $field) {
                        $type = $this->_helper->convertTypesTables($field['DATA_TYPE']);
                        $select = [];
                        if (isset($mergeFields[$key])) {
                            if (!$mergeFields[$key]['delete']) {
                                $type = $mergeFields[$key]['type'];
                                $select = $mergeFields[$key]['options'];
                            }
                        }
                        $options[$name][] = ['field' => $key, 'type' => $type, 'select' => $select];
                    }
                }
            }
        }
        return $options;
    }

    /**
     * @param $model
     * @return array
     * @throws LocalizedException
     */
    public function getChildHeaders($model)
    {
        return array_keys($this->describeTable($model));
    }

    /**
     * @param null|AbstractModel $model
     * @return array
     * @throws LocalizedException
     */
    protected function describeTable($model = null)
    {
        $fields = [];
        if ($model && is_a($model, AbstractModel::class)) {
            $resource = $model->getResource();
            $table = $resource->getMainTable();
            $fields = $resource->getConnection()->describeTable($table);
        }
        return $fields;
    }

    /**
     * @param array $data
     * @param string $table
     * @return array
     * @throws Exception
     */
    protected function _updateData($data, $table)
    {
        if (!isset($this->_parameters[Processor::EXPORT_FILTER_TABLE]) ||
            !is_array($this->_parameters[Processor::EXPORT_FILTER_TABLE])) {
            $exportFilter = [];
        } else {
            $exportFilter = $this->_parameters[Processor::EXPORT_FILTER_TABLE];
        }

        $filters = [];
        $prefix = $this->_prefixData[$table] ?? '';

        foreach ($exportFilter as $filter) {
            if ($filter['entity'] == $table) {
                $field = $prefix ? $prefix . ':' . $filter['field'] : $filter['field'];
                $filters[$field] = $filter['value'];
            }
        }

        if (empty($this->_describeTable[$table])) {
            $this->_describeTable[$table] = $this->_connection->describeTable(
                $this->_resourceModel->getTableName($table)
            );
        }

        $info = [];
        foreach ($this->_describeTable[$table] as $field => $fieldInfo) {
            if ($prefix) {
                $field = $prefix . ':' . $field;
            }
            $info[$field] = $fieldInfo;
        }

        foreach ($data as $field => $value) {
            $dataType = $info[$field]['DATA_TYPE'] ?? null;
            $type = $dataType ? $this->_helper->convertTypesTables($dataType) : null;
            if (isset($filters[$field])) {
                if (!isset($this->filters[$table])) {
                    $this->filters[$table] = [];
                }
                if (empty($this->filters[$table][$field])) {
                    $isValid = false;
                    $filterValue = $filters[$field];
                    if ('text' == $type) {
                        if (is_scalar($filterValue)) {
                            trim($filterValue);
                        }
                        $isValid = mb_stripos($value, $filterValue) !== false;
                    } elseif ('int' == $type) {
                        if (is_array($filterValue) && count($filterValue) == 2) {
                            $from = array_shift($filterValue);
                            $to = array_shift($filterValue);
                            $isValid = $from <= $value && ($to === '' || $to >= $value);
                        } else {
                            $isValid = mb_stripos($value, $filterValue) !== false;
                        }
                    } elseif ('date' == $type) {
                        if (is_array($filterValue) && count($filterValue) == 2) {
                            $from = array_shift($filterValue);
                            $to = array_shift($filterValue);
                            if ($value && $from && $to) {
                                $value = (new DateTime($value))->format('m/d/Y');
                                $from = (new DateTime($from))->format('m/d/Y');
                                $to = (new DateTime($to))->format('m/d/Y');
                                $isValid = ($to >= $value) && ($from <= $value);
                            }
                        }
                    }
                    $this->filters[$table][$field] = $isValid;
                }
            }

            if (in_array($dataType, ['blob', 'mediumblob', 'tinyblob', 'longblob'])) {
                $data[$field] = base64_encode($value);
            }
        }

        $instr = $this->_scopeFields($table);
        $allFields = $this->_parameters['all_fields'];
        if (!$allFields) {
            return $this->_changedColumns($data, $instr);
        }
        return $this->_addPartColumns($data, $instr, $table);
    }

    /**
     * @param string $table
     * @return array
     */
    protected function _scopeFields($table)
    {
        $deps = $this->_parameters['dependencies'];
        $numbers = [];
        foreach ($deps as $ki => $dep) {
            if ($dep == $table) {
                $numbers[] = $ki;
            }
        }
        $listCodes = $this->_filterCodes($numbers, $this->_parameters['list']);
        $replaces = $this->_filterCodes($numbers, $this->_parameters['replace_code']);
        $replacesValues = $this->_filterCodes($numbers, $this->_parameters['replace_value']);
        $instr = [
            'list' => $listCodes,
            'replaces' => $replaces,
            'replacesValues' => $replacesValues
        ];
        return $instr;
    }

    /**
     * @param $numbers
     * @param $list
     * @return array
     */
    protected function _filterCodes($numbers, $list)
    {
        $array = [];
        foreach ($list as $ki => $item) {
            if (in_array($ki, $numbers)) {
                $array[$ki] = $item;
            }
        }
        return $array;
    }

    /**
     * @param array $data
     * @param array $instr
     * @return array
     */
    protected function _changedColumns($data, $instr)
    {
        $newData = [];
        foreach ($data as $code => $value) {
            if (in_array($code, $instr['list'])) {
                $ki = $this->_getKeyFromList($instr['list'], $code);
                $newCode = $code;
                if ($ki !== false && isset($instr['replaces'][$ki])) {
                    $newCode = $instr['replaces'][$ki];
                }
                $newData[$newCode] = $value;
                if ($ki !== false && isset($instr['replacesValues'][$ki])
                    && $instr['replacesValues'][$ki] !== '') {
                    $newData[$newCode] = $instr['replacesValues'][$ki];
                }
            } else {
                $newData[$code] = $value;
            }
        }
        return $newData;
    }

    /**
     * @param $list
     * @param $search
     * @return false|int|string
     */
    protected function _getKeyFromList($list, $search)
    {
        return array_search($search, $list);
    }

    /**
     * @param $data
     * @param $instr
     * @param $table
     *
     * @return array
     */
    protected function _addPartColumns($data, $instr, $table)
    {
        $newData = [];
        $reqCode = "";
        foreach ($this->_exportConfig as $typeName => $type) {
            if ($typeName == 'catalog_category' || !isset($type['fields'])) {
                continue;
            }
            foreach ($type['fields'] as $name => $values) {
                if ($name == $table) {
                    $reqCode = $values['main_field'];
                }
            }
        }
        if (!in_array($reqCode, $instr['list'])
            && isset($data[$reqCode])
        ) {
            $newData[$reqCode] = $data[$reqCode];
        }

        $prefix = $this->_prefixData[$table] ?? '';

        foreach ($instr['list'] as $k => $code) {
            $newCode = $code;
            if (isset($instr['replaces'][$k])) {
                $newCode = $instr['replaces'][$k];
            }
            $newData[$newCode] = $data[$code] ?? '';
            try {
                if ($table !== 'sales_order' && strpos($code, $prefix) !== false) {
                    $newData[$newCode] = $data[$code] ?? '';
                } elseif ($prefix) {
                    $newData[$newCode] = $data[$prefix . ':' . $code] ?? '';
                }
            } catch (Exception $exception) {
                $this->addLogWriteln($code, $this->getOutput(), 'error');
            }
            if (isset($instr['replacesValues'][$k])
                && !empty($instr['replacesValues'][$k])) {
                $newData[$newCode] = $instr['replacesValues'][$k];
            }
        }

        return $newData;
    }

    /**
     * Retrieve attributes codes which are appropriate for export
     *
     * @return array
     */
    protected function _getExportAttrCodes()
    {
        return [];
    }
}
