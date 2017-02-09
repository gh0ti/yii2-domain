<?php

namespace dekey\domain\db;

use dekey\domain\base;
use dekey\domain\contracts;
use dekey\domain\contracts\DomainEntity;
use dekey\domain\data\EntitiesProvider;
use dekey\domain\exceptions\UnableToSaveEntityException;
use dekey\domain\mixins\TransactionAccess;
use yii\base\InvalidConfigException;
use yii\data\ActiveDataProvider;

/**
 * Represents entities DB repository.
 *
 * @package dekey\domain\base
 * @author Dmitry Kolodko <prowwid@gmail.com>
 */
class EntitiesRepository extends base\Component implements contracts\Repository {
    use TransactionAccess;
    /**
     * @var string entities provider class name. Change it in {@link init()} method if you need
     * custom provider.
     */
    public $entitiesProviderClassName = EntitiesProvider::class;
    /**
     * @deprecated use {@link recordsProviderClassName}
     * @var @deprecated use
     */
    public $recordsProviderClassName = ActiveDataProvider::class;
    /**
     * @var string data mapper class name. Required to map data from record to entity. Change it in {@link init()} method
     * if you need custom mapper. But be aware - data mapper is internal class and it is strongly advised to not
     * touch this property.
     */
    public $dataMapperClassName = base\DataMapper::class;
    /**
     * @var string class name of an event that being triggered on each important action. Change it in {@link init()} method
     * if you need custom event.
     */
    public $modelEventClassName = base\ModelEvent::class;
    /**
     * @var bool indicates whether to use DB transaction or not.
     */
    public $useTransactions = true;
    /**
     * @var string entities finder class name. This class being used if no finder specified in morel directory. Change it
     * in {@link init()} method if you need custom default finder.
     */
    private $_defaultFinderClass = Finder::class;
    /**
     * @var string records query class name. This class being used if no query specified in morel directory. Change it
     * in {@link init()} method if you need custom default query.
     */
    private $_defaultQueryClass = RecordQuery::class;

    public function validateAndSave(DomainEntity $entity, $attributes = null) {
        return $this->useTransactions ? $this->saveEntityUsingTransaction($entity, $runValidation = true, $attributes) : $this->saveEntityInternal($entity, $runValidation = true, $attributes);
    }

    public function saveWithoutValidation(DomainEntity $entity, $attributes = null) {
        return $this->useTransactions ? $this->saveEntityUsingTransaction($entity, $runValidation = false, $attributes) : $this->saveEntityInternal($entity, $runValidation = false, $attributes);
    }

    protected function saveEntityUsingTransaction(DomainEntity $entity, $runValidation, $attributes) {
        $this->beginTransaction();
        try {
            $result = $this->saveEntityInternal($entity, $runValidation, $attributes);
            $result ? $this->commitTransaction() : null;
        } catch (\Exception $e) {
            $result = false;
        }
        if (!$result) {
            $this->rollbackTransaction();
        }
        return $result;
    }

    protected function saveEntityInternal(DomainEntity $entity, $runValidation, $attributes) {
        if ($this->triggerModelEvent(self::EVENT_BEFORE_SAVE, $entity)) {
            $dataSource = $entity->getDataMapper()->getDataSource();
            $result = $runValidation ? $dataSource->validateAndSave($attributes) : $dataSource->saveWithoutValidation($attributes);
        } else {
            $result = false;
        }
        if ($result) {
            $this->triggerModelEvent(self::EVENT_AFTER_SAVE, $entity);
        } else {
            $exception = new UnableToSaveEntityException('Failed to save entity ' . get_class($entity));
            $exception->errorsList = $dataSource->getErrors();
            throw $exception;
        }

        return $result;
    }

    public function delete(DomainEntity $entity) {
        if ($this->triggerModelEvent(self::EVENT_BEFORE_DELETE, $entity)) {
            $result = $entity->getDataMapper()->getDataSource()->deleteRecord();
        } else {
            $result = false;
        }
        if ($result) {
            $this->triggerModelEvent(self::EVENT_AFTER_DELETE, $entity);
        }
        return $result;
    }

    /**
     * This method is called at the beginning of inserting or updating a record.
     * The default implementation will trigger an [[EVENT_BEFORE_INSERT]] event when `$insert` is `true`,
     * or an [[EVENT_BEFORE_UPDATE]] event if `$insert` is `false`.
     * When overriding this method, make sure you call the parent implementation like the following:
     *
     * ```php
     * public function beforeSave($insert)
     * {
     *     if (parent::beforeSave($insert)) {
     *         // ...custom code here...
     *         return true;
     *     } else {
     *         return false;
     *     }
     * }
     * ```
     *
     * @param boolean $insert whether this method called while inserting a record.
     * If `false`, it means the method is called while updating a record.
     * @return boolean whether the insertion or updating should continue.
     * If `false`, the insertion or updating will be cancelled.
     */
    protected function triggerModelEvent($eventName, $entity) {
        /**
         * @var ModelEvent $event
         */
        $event = $this->container->create($this->modelEventClassName, [$entity]);
        $this->trigger($eventName, $event);

        return $event->isValid();
    }

    public function validate(DomainEntity $entity) {
        $dataSource = $entity->getDataSource();
        return $dataSource->validate();
    }

    public function createNewEntity() {
        $container = $this->container;
        return $container->create([
            'class' => $this->getEntityClass(),
            'dataMapper' => $container->create($this->dataMapperClassName, [$this->createRecord()]),
        ]);
    }

    private function createRecord() {
        return $this->container->create($this->getRecordClass());
    }

    public function createEntityFromSource(contracts\EntityDataSource $record) {
        $container = $this->container;
        return $container->create([
            'class' => $this->getEntityClass(),
            'dataMapper' => $container->create($this->dataMapperClassName, [$record]),
        ]);
    }

    /**
     * @param mixed $pk primary key of the entity
     * @return Entity[]
     */
    public function findOneWithPk($pk) {
        return $this->find()->oneWithPk($pk);
    }

    /**
     * @return Entity[]
     */
    public function findAll() {
        return $this->find()->all();
    }

    /**
     * @return Entity[]
     */
    public function each() {
        return $this->find()->each();
    }

    /**
     * @return Finder|RecordQuery
     */
    public function find() {
        return $this->createFinder();
    }

    protected function createFinder() {
        return $this->container->create($this->getFinderClass(), [$query = $this->createQuery(), $repository = $this]);
    }

    public function createQuery() {
        return $this->container->create($this->getQueryClass(), [$recordClass = $this->getRecordClass()]);
    }

    /**
     * @return EntitiesProvider an instance of data provider.
     */
    public function getEntitiesProvider() {
        return $this->container->create([
            'class' => $this->entitiesProviderClassName,
            'query' => $this->createQuery(),
            'repository' => $this,
        ]);
    }

    //----------------------- GETTERS FOR DYNAMIC PROPERTIES -----------------------//

    protected function getEntityClass() {
        return $this->buildModelElementClassName('Entity');
    }

    protected function getRecordClass() {
        return $this->buildModelElementClassName('Record');
    }

    protected function getFinderClass() {
        return $this->buildModelElementClassName('Finder', $this->getDefaultFinderClass());
    }

    protected function getQueryClass() {
        return $this->buildModelElementClassName('Query', $this->getDefaultQueryClass());
    }

    protected function buildModelElementClassName($modelElement, $defaultClass = null) {
        $selfClassName = static::class;
        $elementClassName = str_replace('Repository', $modelElement, $selfClassName);
        if (!class_exists($elementClassName) && !interface_exists($elementClassName)) {
            if ($defaultClass) {
                $elementClassName = $defaultClass;
            } else {
                throw new InvalidConfigException("{$modelElement} class should be an existing class or interface!");
            }
        }
        return $elementClassName;
    }

    //----------------------- GETTERS/SETTERS -----------------------//

    protected function getDefaultFinderClass() {
        return $this->_defaultFinderClass;
    }

    protected function setDefaultFinderClass($defaultFinderClass) {
        if (!class_exists($defaultFinderClass) && !interface_exists($defaultFinderClass)) {
            throw new InvalidConfigException('Default finder class should be an existing class or interface!');
        }
        $this->_defaultFinderClass = $defaultFinderClass;
    }

    public function getDefaultQueryClass() {
        return $this->_defaultQueryClass;
    }

    public function setDefaultQueryClass($defaultQueryClass) {
        if (!class_exists($defaultQueryClass) && !interface_exists($defaultQueryClass)) {
            throw new InvalidConfigException('Default query class should be an existing class or interface!');
        }
        $this->_defaultQueryClass = $defaultQueryClass;
    }
}