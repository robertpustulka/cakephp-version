<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Josegonzalez\Version\Model\Behavior;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\I18n\Time;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;

/**
 * This behavior provides a way to version dynamic data by keeping versions
 * in a separate table linked to the original record from another one. Versioned
 * fields can be configured to override those in the main table when fetched or
 * put aside into another property for the same entity.
 *
 * If you want to retrieve all versions for each of the fetched records,
 * you can use the custom `versions` finders that is exposed to the table.
 */
class VersionBehavior extends Behavior
{

    /**
     * Table instance
     *
     * @var \Cake\ORM\Table
     */
    protected $_table;

    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'implementedFinders' => ['versions' => 'findVersions'],
        'versionTable' => 'version',
        'versionField' => 'version_id',
        'fields' => null,
        'foreignKey' => 'foreign_key'
    ];

    /**
     * Constructor hook method.
     *
     * Implement this method to avoid having to overwrite
     * the constructor and call parent.
     *
     * @param array $config The configuration settings provided to this behavior.
     * @return void
     */
    public function initialize(array $config)
    {
        $config = $this->config();
        $this->setupFieldAssociations($config['versionTable']);
    }

    /**
     * Creates the associations between the bound table and every field passed to
     * this method.
     *
     * Additionally it creates a `i18n` HasMany association that will be
     * used for fetching all versions for each record in the bound table
     *
     * @param string $table the table name to use for storing each field version
     * @return void
     */
    public function setupFieldAssociations($table)
    {
        $alias = $this->_table->alias();

        foreach ($this->_fields() as $field) {
            $name = $this->_table->alias() . '_' . $field . '_version';
            $target = TableRegistry::get($name);
            $target->table($table);

            $this->_table->hasOne($name, [
                'targetTable' => $target,
                'foreignKey' => $this->_config['foreignKey'],
                'joinType' => 'LEFT',
                'conditions' => [
                    $name . '.model' => $alias,
                    $name . '.field' => $field,
                ],
                'propertyName' => $field . '_version'
            ]);
        }

        $this->_table->hasMany($table, [
            'foreignKey' => $this->_config['foreignKey'],
            'strategy' => 'subquery',
            'conditions' => ["$table.model" => $alias],
            'propertyName' => '__version',
            'dependent' => true
        ]);
    }

    /**
     * Modifies the entity before it is saved so that versioned fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void
     */
    public function beforeSave(Event $event, Entity $entity, ArrayObject $options)
    {
        $table = $this->_config['versionTable'];
        $newOptions = [$table => ['validate' => false]];
        $options['associated'] = $newOptions + $options['associated'];

        $fields = $this->_fields();
        $values = $entity->extract($fields);

        $model = $this->_table->alias();
        $primaryKey = (array)$this->_table->primaryKey();
        $foreignKey = $this->_extractForeignKey($entity);
        $versionField = $this->_config['versionField'];

        $preexistent = TableRegistry::get($table)->find()
            ->select(['version_id'])
            ->where([
                'model' => $model
            ] + $foreignKey)
            ->order(['id desc'])
            ->limit(1)
            ->hydrate(false)
            ->toArray();

        $versionId = Hash::get($preexistent, '0.version_id', 0) + 1;

        $created = new Time();
        foreach ($values as $field => $content) {
            if (in_array($field, $primaryKey) || $field == $versionField) {
                continue;
            }

            $data = [
                'version_id' => $versionId,
                'model' => $model,
                'field' => $field,
                'content' => $content,
                'created' => $created,
            ] + $foreignKey;

            $event = new Event('Model.Version.beforeSave', $this, $options);
            $userData = EventManager::instance()->dispatch($event);
            if (isset($userData->result) && is_array($userData->result)) {
                $data = array_merge($data, $userData->result);
            }

            $new[$field] = new Entity($data, [
                'useSetters' => false,
                'markNew' => true
            ]);
        }

        $entity->set('__version', $new);
        if (!empty($versionField) && in_array($versionField, $this->_table->schema()->columns())) {
            $entity->set($this->_config['versionField'], $versionId);
        }
    }

    /**
     * Unsets the temporary `__version` property after the entity has been saved
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that is going to be saved
     * @return void
     */
    public function afterSave(Event $event, Entity $entity)
    {
        $entity->unsetProperty('__version');
    }

    /**
     * Custom finder method used to retrieve all versions for the found records.
     *
     * Versioned values will be found for each entity under the property `_versions`.
     *
     * ### Example:
     *
     * {{{
     * $article = $articles->find('versions')->first();
     * $firstVersion = $article->get('_versions')[1];
     * }}}
     *
     * @param \Cake\ORM\Query $query The original query to modify
     * @param array $options Options
     * @return \Cake\ORM\Query
     */
    public function findVersions(Query $query, array $options)
    {
        $table = $this->_config['versionTable'];
        return $query
            ->contain([$table => function ($q) use ($table, $options, $query) {
                if (!empty($options['primaryKey'])) {
                    $foreignKey = (array)$this->_config['foreignKey'];
                    $aliasedFK = [];
                    foreach ($foreignKey as $field) {
                        $aliasedFK[] = current($query->aliasField($field)) . ' IN';
                    }
                    $conditions = array_combine($aliasedFK, (array)$options['primaryKey']);
                    $q->where($conditions);
                }
                if (!empty($options['versionId'])) {
                    $q->where(["$table.version_id IN" => $options['versionId']]);
                }
                $q->where(["$table.field IN" => $this->_fields()]);
                return $q;
            }])
            ->formatResults([$this, 'groupVersions'], $query::PREPEND);
    }

    /**
     * Modifies the results from a table find in order to merge full version records
     * into each entity under the `_versions` key
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to modify.
     * @return \Cake\Collection\Collection
     */
    public function groupVersions($results)
    {
        return $results->map(function ($row) {
            $versions = (array)$row->get('__version');
            $grouped = new Collection($versions);

            $result = [];
            foreach ($grouped->combine('field', 'content', 'version_id') as $versionId => $keys) {
                $version = $this->_table->newEntity($keys + ['version_id' => $versionId], [
                    'markNew' => false,
                    'useSetters' => false,
                    'markClean' => true
                ]);
                $result[$versionId] = $version;
            }

            $options = ['setter' => false, 'guard' => false];
            $row->set('_versions', $result, $options);
            unset($row['__version']);
            $row->clean();
            return $row;
        });
    }

    /**
     * Returns an array of fields to be versioned.
     *
     * @return array
     */
    protected function _fields()
    {
        $schema = $this->_table->schema();
        $fields = $schema->columns();
        if ($this->_config['fields'] !== null) {
            $fields = array_intersect($fields, (array)$this->_config['fields']);
        }

        return $fields;
    }

    /**
     * Returns an array with foreignKey value.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity.
     * @return array
     */
    protected function _extractForeignKey($entity)
    {
        $foreignKey = (array)$this->_config['foreignKey'];
        $primaryKey = (array)$this->_table->primaryKey();
        $pkValue = $entity->extract($primaryKey);

        return array_combine($foreignKey, $pkValue);
    }
}
