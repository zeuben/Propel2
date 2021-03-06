<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

namespace Propel\Generator\Model;

use Propel\Generator\Exception\BuildException;
use Propel\Generator\Exception\EngineException;
use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Platform\MysqlPlatform;

/**
 * Data about a table used in an application.
 *
 * @author Hans Lellelid <hans@xmpl.org> (Propel)
 * @author Leon Messerschmidt <leon@opticode.co.za> (Torque)
 * @author Jason van Zyl <jvanzyl@apache.org> (Torque)
 * @author Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @author John McNally <jmcnally@collab.net> (Torque)
 * @author Daniel Rall <dlr@collab.net> (Torque)
 * @author Byron Foster <byron_foster@yahoo.com> (Torque)
 * @author Hugo Hamon <webmaster@apprendre-php.com>
 */
class Table extends ScopedMappingModel implements IdMethod
{

    /**
     * @var Column[]
     */
    private $columns;

    /**
     * @var ForeignKey[]
     */
    private $foreignKeys;
    private $foreignTableNames;

    /**
     * @var Index[]
     */
    private $indices;

    /**
     * @var Unique[]
     */
    private $unices;
    private $idMethodParameters;
    private $commonName;
    private $description;
    private $phpName;
    private $idMethod;
    private $allowPkInsert;
    private $phpNamingMethod;

    /**
     * @var Database
     */
    private $database;

    private $referrers;
    private $containsForeignPK;
    private $inheritanceColumn;
    private $skipSql;
    private $readOnly;
    private $isAbstract;
    private $alias;
    private $interface;
    private $baseClass;
    private $columnsByName;
    private $columnsByLowercaseName;
    private $columnsByPhpName;
    private $needsTransactionInPostgres;
    private $heavyIndexing;
    private $forReferenceOnly;
    private $reloadOnInsert;
    private $reloadOnUpdate;
    protected $behaviors;
    protected $isCrossRef;
    protected $defaultStringFormat;

    /**
     * Constructs a table object with a name
     *
     * @param string $name table name
     */
    public function __construct($name = null)
    {
        parent::__construct();

        if (null !== $name) {
            $this->setCommonName($name);
        }

        $this->idMethod  = IdMethod::NO_ID_METHOD;
        $this->allowPkInsert = false;
        $this->isAbstract = false;
        $this->isCrossRef = false;
        $this->readOnly = false;
        $this->reloadOnInsert = false;
        $this->reloadOnUpdate = false;
        $this->skipSql = false;

        $this->behaviors = array();
        $this->columns = array();
        $this->columnsByName = array();
        $this->columnsByPhpName = array();
        $this->columnsByLowercaseName = array();
        $this->foreignKeys = array();
        $this->foreignTableNames = array();
        $this->idMethodParameters = array();
        $this->indices = array();
        $this->referrers = array();
        $this->unices = array();
    }

    /**
     * Returns a qualified name of this table with scheme and common name
     * separated by '_'.
     *
     * If schemaAutoPrefix is set. Otherwise get the common name.
     *
     * @return string
     */
    private function getStdSeparatedName()
    {
        if ($this->schema && $this->getBuildProperty('schemaAutoPrefix')) {
            return $this->schema . NameGenerator::STD_SEPARATOR_CHAR . $this->getCommonName();
        }

        return $this->getCommonName();
    }

    public function setupObject()
    {
        parent::setupObject();

        $this->commonName = $this->database->getTablePrefix() . $this->getAttribute('name');

        // retrieves the method for converting from specified name to a PHP name.
        $this->phpNamingMethod = $this->getAttribute('phpNamingMethod', $this->database->getDefaultPhpNamingMethod());

        $this->phpName = $this->getAttribute('phpName', $this->buildPhpName($this->getStdSeparatedName()));

        $this->idMethod = $this->getAttribute('idMethod', $this->database->getDefaultIdMethod());
        $this->allowPkInsert = $this->booleanValue($this->getAttribute('allowPkInsert'));

        $this->skipSql = $this->booleanValue($this->getAttribute('skipSql'));
        $this->readOnly = $this->booleanValue($this->getAttribute('readOnly'));

        $this->isAbstract = $this->booleanValue($this->getAttribute('abstract'));
        $this->baseClass = $this->getAttribute('baseClass');
        $this->alias = $this->getAttribute('alias');

        $this->heavyIndexing = (
            $this->booleanValue($this->getAttribute('heavyIndexing'))
            || (
                'false' !== $this->getAttribute('heavyIndexing')
                && $this->database->isHeavyIndexing()
            )
        );

        $this->description = $this->getAttribute('description');
        $this->interface = $this->getAttribute('interface'); // sic ('interface' is reserved word)

        $this->reloadOnInsert = $this->booleanValue($this->getAttribute('reloadOnInsert'));
        $this->reloadOnUpdate = $this->booleanValue($this->getAttribute('reloadOnUpdate'));
        $this->isCrossRef = $this->booleanValue($this->getAttribute('isCrossRef', false));
        $this->defaultStringFormat = $this->getAttribute('defaultStringFormat');
    }

    /**
     * Returns a build property value for the database this table belongs to.
     *
     * @param  string $key
     * @return string
     */
    public function getBuildProperty($key)
    {
        return $this->database ? $this->database->getBuildProperty($key) : '';
    }

    /**
     * Executes behavior table modifiers.
     *
     */
    public function applyBehaviors()
    {
        foreach ($this->behaviors as $behavior) {
            if (!$behavior->isTableModified()) {
                $behavior->getTableModifier()->modifyTable();
                $behavior->setTableModified(true);
            }
        }
    }

    /**
     * <p>A hook for the SAX XML parser to call when this table has
     * been fully loaded from the XML, and all nested elements have
     * been processed.</p>
     *
     * <p>Performs heavy indexing and naming of elements which weren't
     * provided with a name.</p>
     */
    public function doFinalInitialization()
    {
        // Heavy indexing must wait until after all columns composing
        // a table's primary key have been parsed.
        if ($this->heavyIndexing) {
            $this->doHeavyIndexing();
        }

        // Name any indices which are missing a name using the
        // appropriate algorithm.
        $this->doNaming();

        // if idMethod is "native" and in fact there are no autoIncrement
        // columns in the table, then change it to "none"
        $anyAutoInc = false;
        foreach ($this->columns as $column) {
            if ($column->isAutoIncrement()) {
                $anyAutoInc = true;
            }
        }
        if (IdMethod::NATIVE === $this->getIdMethod() && !$anyAutoInc) {
            $this->setIdMethod(IdMethod::NO_ID_METHOD);
        }
    }

    /**
     * Adds extra indices for multi-part primary key columns.
     *
     * For databases like MySQL, values in a where clause much
     * match key part order from the left to right.	 So, in the key
     * definition <code>PRIMARY KEY (FOO_ID, BAR_ID)</code>,
     * <code>FOO_ID</code> <i>must</i> be the first element used in
     * the <code>where</code> clause of the SQL query used against
     * this table for the primary key index to be used.	 This feature
     * could cause problems under MySQL with heavily indexed tables,
     * as MySQL currently only supports 16 indices per table (i.e. it
     * might cause too many indices to be created).
     *
     * See the mysqm manual http://www.mysql.com/doc/E/X/EXPLAIN.html
     * for a better description of why heavy indexing is useful for
     * quickly searchable database tables.
     */
    private function doHeavyIndexing()
    {
        $pk = $this->getPrimaryKey();
        $size = count($pk);

        // We start at an offset of 1 because the entire column
        // list is generally implicitly indexed by the fact that
        // it's a primary key.
        for ($i = 1; $i < $size; $i++) {
            $idx = new Index();
            $idx->setColumns(array_slice($pk, $i, $size));
            $this->addIndex($idx);
        }
    }

    /**
     * Adds extra indices for reverse foreign keys
     * This is required for MySQL databases,
     * and is called from Database::doFinalInitialization()
     */
    public function addExtraIndices()
    {
        /**
         * A collection of indexed columns. The keys is the column name
         * (concatenated with a comma in the case of multi-col index), the value is
         * an array with the names of the indexes that index these columns. We use
         * it to determine which additional indexes must be created for foreign
         * keys. It could also be used to detect duplicate indexes, but this is not
         * implemented yet.
         * @var array
         */
        $_indices = array();

        $this->collectIndexedColumns('PRIMARY', $this->getPrimaryKey(), $_indices);

        $_tableIndices = array_merge($this->getIndices(), $this->getUnices());
        foreach ($_tableIndices as $_index) {
            $this->collectIndexedColumns($_index->getName(), $_index->getColumns(), $_indices);
        }

        // we're determining which tables have foreign keys that point to this table,
        // since MySQL needs an index on any column that is referenced by another table
        // (yep, MySQL _is_ a PITA)
        $counter = 0;
        foreach ($this->referrers as $foreignKey) {
            $referencedColumns = $foreignKey->getForeignColumnObjects();
            $referencedColumnsHash = $this->getColumnList($referencedColumns);
            if (!empty($referencedColumns) && !isset($_indices[$referencedColumnsHash])) {
                // no matching index defined in the schema, so we have to create one

                $name = sprintf('I_referenced_%s_%s', $foreignKey->getName(), ++$counter);
                if ($this->hasIndex($name)) {
                    //if we have already a index with this name, then it looks like the columns of this index have just
                    //been changed, so remove it and inject it again. This is the case if a referenced table is handled
                    //later than the referencing table.
                    $this->removeIndex($name);
                }

                $index = new Index();
                $index->setName($name);
                $index->setColumns($referencedColumns);
                $index->resetColumnsSize();
                $this->addIndex($index);
                // Add this new index to our collection, otherwise we might add it again (bug #725)
                $this->collectIndexedColumns($index->getName(), $referencedColumns, $_indices);
            }
        }

        // we're adding indices for this table foreign keys
        foreach ($this->foreignKeys as $foreignKey) {
            $localColumns = $foreignKey->getLocalColumnObjects();
            $localColumnsHash = $this->getColumnList($localColumns);
            if (!empty($localColumns) && !isset($_indices[$localColumnsHash])) {
                // no matching index defined in the schema, so we have to create one.
                // MySQL needs indices on any columns that serve as foreign keys. these are not auto-created prior to
                // 4.1.2

                $name = substr_replace($foreignKey->getName(), 'FI_',  strrpos($foreignKey->getName(), 'FK_'), 3);
                if ($this->hasIndex($name)) {
                    //if we have already a index with this name, then it looks like the columns of this index have just
                    //been changed, so remove it and inject it again. This is the case if a referenced table is handled
                    //later than the referencing table.
                    $this->removeIndex($name);
                }

                $index = new Index();
                $index->setName($name);
                $index->setColumns($localColumns);
                $index->resetColumnsSize();
                $this->addIndex($index);
                $this->collectIndexedColumns($index->getName(), $localColumns, $_indices);
            }
        }
    }

    /**
     * Helper function to collect indexed columns.
     *
     * @param string $indexName        The name of the index
     * @param array  $columns          The column names or objects
     * @param array  $collectedIndexes The collected indexes
     */
    protected function collectIndexedColumns($indexName, $columns, &$collectedIndexes)
    {
        /**
         * "If the table has a multiple-column index, any leftmost prefix of the
         * index can be used by the optimizer to find rows. For example, if you
         * have a three-column index on (col1, col2, col3), you have indexed search
         * capabilities on (col1), (col1, col2), and (col1, col2, col3)."
         * @link http://dev.mysql.com/doc/refman/5.5/en/mysql-indexes.html
         */
        $indexedColumns = array();
        foreach ($columns as $column) {
            $indexedColumns[] = $column;
            $indexedColumnsHash = $this->getColumnList($indexedColumns);
            if (!isset($collectedIndexes[$indexedColumnsHash])) {
                $collectedIndexes[$indexedColumnsHash] = array();
            }
            $collectedIndexes[$indexedColumnsHash][] = $indexName;
        }
    }

    /**
     * Returns a delimiter-delimited string list of column names.
     *
     * @see Platform::getColumnList() if quoting is required
     * @param array
     * @param  string $delimiter
     * @return string
     */
    public function getColumnList($columns, $delimiter = ',')
    {
        $list = array();
        foreach ($columns as $col) {
            if ($col instanceof Column) {
                $col = $col->getName();
            }
            $list[] = $col;
        }

        return implode($delimiter, $list);
    }

    /**
     * Names composing objects which haven't yet been named.	This
     * currently consists of foreign-key and index entities.
     */
    public function doNaming()
    {
        for ($i = 0, $size = count($this->foreignKeys); $i < $size; $i++) {
            $fk = $this->foreignKeys[$i];
            $name = $fk->getName();
            if (empty($name)) {
                $name = $this->acquireConstraintName('FK', $i + 1);
                $fk->setName($name);
            }
        }

        for ($i = 0, $size = count($this->indices); $i < $size; $i++) {
            $index = $this->indices[$i];
            $name = $index->getName();
            if (empty($name)) {
                $name = $this->acquireConstraintName('I', $i + 1);
                $index->setName($name);
            }
        }

        for ($i = 0, $size = count($this->unices); $i < $size; $i++) {
            $index = $this->unices[$i];
            $name = $index->getName();
            if (empty($name)) {
                $name = $this->acquireConstraintName('U', $i + 1);
                $index->setName($name);
            }
        }

        // NOTE: Most RDBMSes can apparently name unique column
        // constraints/indices themselves (using MySQL and Oracle
        // as test cases), so we'll assume that we needn't add an
        // entry to the system name list for these.
    }

    /**
     * Macro to a constraint name.
     *
     * @param  string          $nameType Constraint type
     * @param  integer         $nbr      Unique number for this constraint type
     * @return string          Unique name for constraint
     * @throws EngineException
     */
    private function acquireConstraintName($nameType, $nbr)
    {
        $inputs   = array();
        $inputs[] = $this->database;
        $inputs[] = $this->getCommonName();
        $inputs[] = $nameType;
        $inputs[] = $nbr;

        return NameFactory::generateName(NameFactory::CONSTRAINT_GENERATOR, $inputs);
    }

    /**
     * Returns the name of the base class used for superclass of all objects
     * of this table.
     *
     * @return string
     */
    public function getBaseClass()
    {
        if ($this->isAlias() && null === $this->baseClass) {
            return $this->alias;
        }

        if (null === $this->baseClass) {
            return $this->database->getBaseClass();
        }

        return $this->baseClass;
    }

    /**
     * Sets the base class name.
     *
     * @param string $class
     */
    public function setBaseClass($class)
    {
        $this->baseClass = $class;
    }

    /**
     * Adds a new column to the table.
     *
     * @param  Column|array $col
     * @return Column
     */
    public function addColumn($col)
    {
        if ($col instanceof Column) {

            if (isset($this->columnsByName[$col->getName()])) {
                throw new EngineException(sprintf('Column "%s" declared twice in table "%s"', $col->getName(), $this->getName()));
            }

            $col->setTable($this);

            if ($col->isInheritance()) {
                $this->inheritanceColumn = $col;
            }

            $this->columns[] = $col;
            $this->columnsByName[$col->getName()] = $col;
            $this->columnsByLowercaseName[strtolower($col->getName())] = $col;
            $this->columnsByPhpName[$col->getPhpName()] = $col;
            $col->setPosition(count($this->columns));

            if ($col->requiresTransactionInPostgres()) {
                $this->needsTransactionInPostgres = true;
            }

            return $col;
        }

        $column = new Column();
        $column->setTable($this);
        $column->loadMapping($col);

        return $this->addColumn($column); // call self w/ different param
    }

    /**
     * Removes a column from the table.
     *
     * @param Column|string $column The Column or its name
     */
    public function removeColumn($column)
    {
        if (is_string($column)) {
            $column = $this->getColumn($column);
        }

        $pos = $this->getColumnPosition($column);
        if (false === $pos) {
            throw new EngineException(sprintf('No column named %s found in table %s.', $column->getName(), $this->getName()));
        }

        unset($this->columns[$pos]);
        unset($this->columnsByName[$column->getName()]);
        unset($this->columnsByLowercaseName[strtolower($column->getName())]);
        unset($this->columnsByPhpName[$column->getPhpName()]);

        $this->adjustColumnPositions();

        // @FIXME: also remove indexes and validators on this column?
    }

    private function getColumnPosition(Column $column)
    {
        $position = false;
        for ($pos = 0; $pos < $this->getNumColumns(); $pos++) {
            if ($this->columns[$pos] === $column) {
                $position = $pos;
            }
        }

        return $position;
    }

    public function adjustColumnPositions()
    {
        $this->columns = array_values($this->columns);
        $columnCount = $this->getNumColumns();
        for ($i = 0; $i < $columnCount; $i++) {
            $this->columns[$i]->setPosition($i + 1);
        }
    }

    /**
     * Adds a new foreign key to this table.
     *
     * @param ForeignKey|array
     * @return ForeignKey
     */
    public function addForeignKey($fkdata)
    {
        if ($fkdata instanceof ForeignKey) {
            $fk = $fkdata;
            $fk->setTable($this);
            $this->foreignKeys[] = $fk;

            if (!in_array($fk->getForeignTableName(), $this->foreignTableNames)) {
                $this->foreignTableNames[] = $fk->getForeignTableName();
            }

            return $fk;
        }

        $fk = new ForeignKey();
        $fk->setTable($this);
        $fk->loadMapping($fkdata);

        return $this->addForeignKey($fk);
    }

    /**
     * Returns the column that subclasses the class representing this
     * table can be produced from.
     *
     * @return Column
     */
    public function getChildrenColumn()
    {
        return $this->inheritanceColumn;
    }

    /**
     * Returns the subclasses that can be created from this table.
     *
     * @return array
     */
    public function getChildrenNames()
    {
        if (null === $this->inheritanceColumn
            || !$this->inheritanceColumn->isEnumeratedClasses()) {
            return null;
        }

        $names = array();
        foreach ($this->inheritanceColumn->getChildren() as $child) {
            $names[] = get_class($child);
        }

        return $names;
    }

    /**
     * Adds the foreign key from another table that refers to this table.
     *
     * @param ForeignKey $fk
     */
    public function addReferrer(ForeignKey $fk)
    {
        $this->referrers[] = $fk;
    }

    /**
     * Returns the list of references to this table.
     *
     * @return array
     */
    public function getReferrers()
    {
        return $this->referrers;
    }

    /**
     * Browses the foreign keys and creates referrers for the foreign table.
     * This method can be called several times on the same table. It only
     * adds the missing referrers and is non-destructive.
     * Warning: only use when all the tables were created.
     *
     * @param boolean $throwErrors
     */
    public function setupReferrers($throwErrors = false)
    {
        foreach ($this->foreignKeys as $foreignKey) {

            // table referrers
            $foreignTable = $this->database->getTable($foreignKey->getForeignTableName());
            if (null !== $foreignTable) {
                $referrers = $foreignTable->getReferrers();
                if (null === $referrers || !in_array($foreignKey, $referrers, true) ) {
                    $foreignTable->addReferrer($foreignKey);
                }
            } elseif ($throwErrors) {
                throw new BuildException(sprintf(
                    'Table "%s" contains a foreign key to nonexistent table "%s"',
                    $this->getName(),
                    $foreignKey->getForeignTableName()
                ));
            }

            // foreign pk's
            $localColumnNames = $foreignKey->getLocalColumns();
            foreach ($localColumnNames as $localColumnName) {
                $localColumn = $this->getColumn($localColumnName);
                if (null !== $localColumn) {
                    if ($localColumn->isPrimaryKey() && !$this->getContainsForeignPK()) {
                        $this->setContainsForeignPK(true);
                    }
                } elseif ($throwErrors) {
                    // give notice of a schema inconsistency.
                    // note we do not prevent the npe as there is nothing
                    // that we can do, if it is to occur.
                    throw new BuildException(sprintf(
                        'Table "%s" contains a foreign key with nonexistent local column "%s"',
                        $this->getName(),
                        $localColumnName
                    ));
                }
            }

            // foreign column references
            $foreignColumnNames = $foreignKey->getForeignColumns();
            foreach ($foreignColumnNames as $foreignColumnName) {
                if (null === $foreignTable) {
                    continue;
                }
                $foreignColumn = $foreignTable->getColumn($foreignColumnName);
                if (null !== $foreignColumn) {
                    if (!$foreignColumn->hasReferrer($foreignKey)) {
                        $foreignColumn->addReferrer($foreignKey);
                    }
                } elseif ($throwErrors) {
                    // if the foreign column does not exist, we may have an
                    // external reference or a misspelling
                    throw new BuildException(sprintf(
                        'Table "%s" contains a foreign key to table "%s" with nonexistent column "%s"',
                        $this->getName(),
                        $foreignTable->getName(),
                        $foreignColumnName
                    ));
                }
            }

            if ($this->getPlatform() instanceof MysqlPlatform) {
                $this->addExtraIndices();
            }
        }
    }

    /**
     * Returns the list of cross foreign keys.
     *
     * @return array
     */
    public function getCrossFks()
    {
        $crossFks = array();
        foreach ($this->referrers as $refFK) {
            if ($refFK->getTable()->isCrossRef()) {
                foreach ($refFK->getOtherFks() as $crossFK) {
                    $crossFks[] = array($refFK, $crossFK);
                }
            }
        }

        return $crossFks;
    }

    /**
     * Sets whether or not this table contains a foreign primary key.
     *
     * @return boolean
     */
    public function setContainsForeignPK($b)
    {
        $this->containsForeignPK = (Boolean) $b;
    }

    /**
     * Returns whether or not this table contains a foreign primary key.
     *
     * @return boolean
     */
    public function getContainsForeignPK()
    {
        return $this->containsForeignPK;
    }

    /**
     * Returns the list of tables referenced by foreign keys in this table.
     *
     * @return array
     */
    public function getForeignTableNames()
    {
        return $this->foreignTableNames;
    }

    /**
     * Return true if the column requires a transaction in Postgres.
     *
     * @return boolean
     */
    public function requiresTransactionInPostgres()
    {
        return $this->needsTransactionInPostgres;
    }

    /**
     * Adds a new parameter for the strategy that generates primary keys.
     *
     * @param IdMethodParameter $idMethodParameter
     */
    public function addIdMethodParameter($idMethodParameter)
    {
        if ($idMethodParameter instanceof IdMethodParameter) {
            $idMethodParameter->setTable($this);
            $this->idMethodParameters[] = $idMethodParameter;

            return $idMethodParameter;
        }

        $imp = new IdMethodParameter();
        $imp->setTable($this);
        $imp->loadMapping($idMethodParameter);

        return $this->addIdMethodParameter($imp);
    }

    /**
     * Removes a index from the table.
     *
     * @param string $name
     */
    public function removeIndex($name)
    {
        //check if we have a index with this name already, then delete it
        foreach ($this->indices as $n => $idx) {
            if ($idx->getName() == $name) {
                unset($this->indices[$n]);

                return;
            }
        }
    }

    /**
     * Checks if the table has a index by name.
     *
     * @param  string $name
     * @return bool
     */
    public function hasIndex($name)
    {
        foreach ($this->indices as $idx) {
            if ($idx->getName() == $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adds a new index to the indices list and set the
     * parent table of the column to the current table.
     *
     * @param  Index $index
     * @return Index
     */
    public function addIndex($index)
    {
        if ($index instanceof Index) {
            $index->setTable($this);
            // force the name to be created if empty.
            $index->getName();
            $this->indices[] = $index;

            return $index;
        }

        $idx = new Index();
        $idx->loadMapping($index);

        return $this->addIndex($idx);
    }

    /**
     * Adds a new Unique index to the list of unique indices and set the
     * parent table of the column to the current table.
     *
     * @param  Unique $unique
     * @return Unique
     */
    public function addUnique($unique)
    {
        if ($unique instanceof Unique) {
            $unique->setTable($this);
            $unique->getName(); // we call this method so that the name is created now if it doesn't already exist.
            $this->unices[] = $unique;

            return $unique;
        }

        $unik = new Unique();
        $unik->loadMapping($unique);

        return $this->addUnique($unik);
    }

    /**
     * Retrieves the configuration object, filled by build.properties
     *
     * @return GeneratorConfig
     */
    public function getGeneratorConfig()
    {
        return $this->database->getParentSchema()->getGeneratorConfig();
    }

    /**
     * Adds a new Behavior to the table.
     *
     * @return Behavior $bdata
     */
    public function addBehavior($bdata)
    {
        if ($bdata instanceof Behavior) {
            $behavior = $bdata;
            $behavior->setTable($this);
            $this->behaviors[$behavior->getName()] = $behavior;

            return $behavior;
        }

        $class = $this->getConfiguredBehavior($bdata['name']);
        $behavior = new $class();
        $behavior->loadMapping($bdata);

        return $this->addBehavior($behavior);
    }

    /**
     * Returns the list of table behaviors.
     *
     * @return array
     */
    public function getBehaviors()
    {
        return $this->behaviors;
    }

    /**
     * check if the table has a behavior by name
     *
     * @param  string  $name the behavior name
     * @return boolean True if the behavior exists
     */
    public function hasBehavior($name)
    {
        return isset($this->behaviors[$name]);
    }

    /**
     * Get one table behavior by name
     *
     * @param  string   $name the behavior name
     * @return Behavior a behavior object
     */
    public function getBehavior($name)
    {
        return $this->behaviors[$name];
    }

    /**
     * Returns whether or not the table behaviors offer additional builders.
     *
     * @return boolean
     */
    public function hasAdditionalBuilders()
    {
        foreach ($this->behaviors as $behavior) {
            if ($behavior->hasAdditionalBuilders()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the early table behaviors
     * @return Array of Behavior objects
     */
    public function getEarlyBehaviors()
    {
        $behaviors = array();
        foreach ($this->behaviors as $name => $behavior) {
            if ($behavior->isEarly()) {
                $behaviors[$name] = $behavior;
            }
        }

        return $behaviors;
    }

    /**
     * Returns the list of additional builders provided by the table behaviors.
     *
     * @return array
     */
    public function getAdditionalBuilders()
    {
        $additionalBuilders = array();
        foreach ($this->behaviors as $behavior) {
            $additionalBuilders = array_merge($additionalBuilders, $behavior->getAdditionalBuilders());
        }

        return $additionalBuilders;
    }

    /**
     * Returns the full table name (including schema name when possible).
     *
     * @return string
     */
    public function getName()
    {
        if ($this->getDatabase()
            && ($this->schema || $this->getDatabase()->getSchema())
            && $this->getDatabase()->getPlatform()
            && $this->getDatabase()->getPlatform()->supportsSchemas()
        ) {
            return ($this->schema ?: $this->getDatabase()->getSchema())
                . $this->getDatabase()->getPlatform()->getSchemaDelimiter()
                . $this->commonName;
        }

        return $this->commonName;
    }

    /**
     * Returns the table description.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Returns whether or not the table has a description.
     *
     * @return boolean
     */
    public function hasDescription()
    {
        return !empty($this->description);
    }

    /**
     * Sets the table description.
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Returns the name to use in PHP sources.
     *
     * @return string
     * @throws EngineException
     */
    public function getPhpName()
    {
        if (null === $this->phpName) {
            $inputs = array();
            $inputs[] = $this->getStdSeparatedName();
            $inputs[] = $this->phpNamingMethod;

            $this->phpName = NameFactory::generateName(NameFactory::PHP_GENERATOR, $inputs);
        }

        return $this->phpName;
    }

    /**
     * Sets the name to use in PHP sources.
     *
     * @param string $phpName
     */
    public function setPhpName($phpName)
    {
        $this->phpName = $phpName;
    }

    /**
     * Returns the auto generated PHP name value for a given name.
     *
     * @param string $name
     */
    private function buildPhpName($name)
    {
        return NameFactory::generateName(NameFactory::PHP_GENERATOR, array($name, $this->phpNamingMethod));
    }

    /**
     * Returns the studly version of PHP name.
     *
     * The studly name is the PHP name with the first character lowercase.
     *
     * @return string
     */
    public function getStudlyPhpName()
    {
        return lcfirst($this->getPhpName());
    }

    /**
     * Returns the common name (without schema name).
     *
     * @return string
     */
    public function getCommonName()
    {
        return $this->commonName;
    }

    /**
     * Sets the table common name (without schema name).
     *
     * @param string $name
     */
    public function setCommonName($name)
    {
        $this->commonName = $name;
    }

    /**
     * Sets the default string format for ActiveRecord objects in this table.
     *
     * Any of 'XML', 'YAML', 'JSON', or 'CSV'.
     *
     * @param  string                   $format
     * @throws InvalidArgumentException
     */
    public function setDefaultStringFormat($format)
    {
        $formats = Database::getSupportedStringFormats();

        $format = strtoupper($format);
        if (!in_array($format, $formats)) {
            throw new InvalidArgumentException(sprintf('Given "%s" default string format is not supported. Only "%s" are valid string formats.', $format, implode(', ', $formats)));
        }

        $this->defaultStringFormat = $format;
    }

    /**
     * Returns the default string format for ActiveRecord objects in this table,
     * or the one for the whole database if not set.
     *
     * @return string
     */
    public function getDefaultStringFormat()
    {
        if (null !== $this->defaultStringFormat) {
            return $this->defaultStringFormat;
        }

        return $this->database->getDefaultStringFormat();
    }

    /**
     * Returns the method strategy for generating primary keys.
     *
     * [HL] changing behavior so that Database default method is returned
     * if no method has been specified for the table.
     *
     * @return string
     */
    public function getIdMethod()
    {
        return $this->idMethod;
    }

    /**
     * Returns whether we allow to insert primary keys on tables with
     * native id method.
     *
     * @return boolean
     */
    public function isAllowPkInsert()
    {
        return $this->allowPkInsert;
    }

    /**
     * Sets the method strategy for generating primary keys.
     *
     * @param string $idMethod
     */
    public function setIdMethod($idMethod)
    {
        $this->idMethod = $idMethod;
    }

    /**
     * Returns whether or not Propel has to skip DDL SQL generation for this
     * table (in the event it should not be created from scratch).
     *
     * @return boolean
     */
    public function isSkipSql()
    {
        return ($this->skipSql || $this->isAlias() || $this->isForReferenceOnly());
    }

    /**
     * Sets whether or not this table should have its SQL DDL code generated.
     *
     * @param boolean $skip
     */
    public function setSkipSql($skip)
    {
        $this->skipSql = (Boolean) $skip;
    }

    /**
     * Returns whether or not this table is read-only. If yes, only only
     * accessors and relationship accessors and mutators will be generated.
     *
     * @return boolean
     */
    public function isReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * Whether to force object to reload on INSERT.
     * @return boolean
     */
    public function isReloadOnInsert()
    {
        return $this->reloadOnInsert;
    }

    /**
     * Returns whether or not to force object to reload on UPDATE.
     *
     * @return boolean
     */
    public function isReloadOnUpdate()
    {
        return $this->reloadOnUpdate;
    }

    /**
     * Returns the PHP name of an active record object this entry references.
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * Returns whether or not this table is specified in the schema or if there
     * is just a foreign key reference to it.
     *
     * @return boolean
     */
    public function isAlias()
    {
        return null !== $this->alias;
    }

    /**
     * Sets whether or not this table is specified in the schema or if there is
     * just a foreign key reference to it.
     *
     * @param string $alias
     */
    public function setAlias($alias)
    {
        $this->alias = $alias;
    }

    /**
     * Returns the interface objects of this table will implement.
     *
     * @return string
     */
    public function getInterface()
    {
        return $this->interface;
    }

    /**
     * Sets the interface objects of this table will implement.
     *
     * @param string $interface
     */
    public function setInterface($interface)
    {
        $this->interface = $interface;
    }

    /**
     * Returns whether or not a table is abstract, it marks the business object
     * class that is generated as being abstract. If you have a table called
     * "FOO", then the Foo business object class will be declared abstract. This
     * helps support class hierarchies
     *
     * @return boolean
     */
    public function isAbstract()
    {
        return $this->isAbstract;
    }

    /**
     * Sets whether or not a table is abstract, it marks the business object
     * class that is generated as being abstract. If you have a
     * table called "FOO", then the Foo business object class will be
     * declared abstract. This helps support class hierarchies
     *
     * @param boolean $isAbstract
     */
    public function setAbstract($isAbstract)
    {
        $this->isAbstract = (Boolean) $isAbstract;
    }

    /**
     * Returns an array containing all Column objects in the table.
     *
     * @return Column[]
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Returns the number of columns in this table.
     *
     * @return integer
     */
    public function getNumColumns()
    {
        return count($this->columns);
    }

    /**
     * Returns the number of lazy loaded columns in this table.
     *
     * @return integer
     */
    public function getNumLazyLoadColumns()
    {
        $count = 0;
        foreach ($this->columns as $col) {
            if ($col->isLazyLoad()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns whether or not one of the columns is of type ENUM.
     *
     * @return boolean
     */
    public function hasEnumColumns()
    {
        foreach ($this->columns as $col) {
            if ($col->isEnumType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the list of all foreign keys.
     *
     * @return ForeignKey[]
     */
    public function getForeignKeys()
    {
        return $this->foreignKeys;
    }

    /**
     * Returns a Collection of parameters relevant for the chosen
     * id generation method.
     */
    public function getIdMethodParameters()
    {
        return $this->idMethodParameters;
    }

    /**
     * Returns the list of all indices of this table.
     *
     * @return array
     */
    public function getIndices()
    {
        return $this->indices;
    }

    /**
     * Returns the list of all unique indices of this table.
     *
     * @return array
     */
    public function getUnices()
    {
        return $this->unices;
    }

    /**
     * Checks if $keys are a unique constraint in the table.
     * (through primaryKey, through a regular unices constraints or for single keys when it has isUnique=true)
     *
     * @param Column[]|string[] $keys
     * @return bool
     */
    public function isUnique(array $keys)
    {
        if (1 === count($keys)) {
            $column = $keys[0] instanceof Column ? $keys[0] : $this->getColumn($keys[0]);
            if ($column){
                if ($column->isUnique()) return true;
                if ($column->isPrimaryKey() && 1 === count($column->getTable()->getPrimaryKey())) {
                    return true;
                }
            }
        }

        // check if pk == $keys
        if (count($this->getPrimaryKey()) === count($keys)) {
            $allPk = true;
            $stringArray = is_string($keys[0]);
            foreach ($this->getPrimaryKey() as $pk) {
                if ($stringArray) {
                    if (!in_array($pk->getName(), $keys)) {
                        $allPk = false;
                        break;
                    }
                } else {
                    if (!in_array($pk, $keys)) {
                        $allPk = false;
                        break;
                    }
                }
            }

            if ($allPk) {
                return true;
            }
        }

        // check if there is a unique constrains that contains exactly the $keys
        if ($this->unices) {
            foreach ($this->unices as $unique) {
                if (count($unique->getColumns()) === count($keys)) {
                    $allAvailable = true;
                    foreach ($keys as $key) {
                        if (!$unique->hasColumn($key instanceof Column ? $key->getName() : $key)) {
                            $allAvailable = false;
                            break;
                        }
                    }
                    if ($allAvailable) {
                        return true;
                    }
                } else {
                    continue;
                }
            }
        }

        return false;
    }


    /**
     * Checks if a index exists with the given $keys.
     *
     * @param array $keys
     * @return boolean
     */
    public function isIndex(array $keys)
    {
        if ($this->indices) {
            foreach ($this->indices as $index) {
                if (count($keys) === count($index->getColumns())) {
                    $allAvailable = true;
                    foreach ($keys as $key) {
                        if (!$index->hasColumn($key instanceof Column ? $key->getName() : $key)) {
                            $allAvailable = false;
                            break;
                        }
                    }
                    if ($allAvailable) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Returns whether or not the table has a column.
     *
     * @param  Column|string $column          The Column object or its name
     * @param  boolean       $caseInsensitive Whether the check is case insensitive.
     * @return boolean
     */
    public function hasColumn($column, $caseInsensitive = false)
    {
        if ($column instanceof Column) {
            $column = $column->getName();
        }

        if ($caseInsensitive) {
            return isset($this->columnsByLowercaseName[strtolower($column)]);
        }

        return isset($this->columnsByName[$column]);
    }

    /**
     * Returns the Column object with the specified name.
     *
     * @param  string  $name            The name of the column (e.g. 'my_column')
     * @param  boolean $caseInsensitive Whether the check is case insensitive.
     * @return Column
     */
    public function getColumn($name, $caseInsensitive = false)
    {
        if (!$this->hasColumn($name, $caseInsensitive)) {
            return null; // just to be explicit
        }

        if ($caseInsensitive) {
            return $this->columnsByLowercaseName[strtolower($name)];
        }

        return $this->columnsByName[$name];
    }

    /**
     * Returns a specified column by its php name.
     *
     * @param  string $phpName
     * @return Column
     */
    public function getColumnByPhpName($phpName)
    {
        if (isset($this->columnsByPhpName[$phpName])) {
            return $this->columnsByPhpName[$phpName];
        }

        return null;
    }

    /**
     * Returns all foreign keys from this table that reference the table passed
     * in argument.
     *
     * @param  string $tableName
     * @return array
     */
    public function getForeignKeysReferencingTable($tableName)
    {
        $matches = array();
        foreach ($this->foreignKeys as $fk) {
            if ($fk->getForeignTableName() === $tableName) {
                $matches[] = $fk;
            }
        }

        return $matches;
    }

    /**
     * Returns the foreign keys that include column in it's list of local
     * columns.
     *
     * Eg. Foreign key (a, b, c) references tbl(x, y, z) will be returned of col
     * is either a, b or c.
     *
     * @param  string $column Name of the column
     * @return array
     */
    public function getColumnForeignKeys($column)
    {
        $matches = array();
        foreach ($this->foreignKeys as $fk) {
            if (in_array($column, $fk->getLocalColumns())) {
                $matches[] = $fk;
            }
        }

        return $matches;
    }

    /**
     * Set the database that contains this table.
     *
     * @param Database $database
     */
    public function setDatabase(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Get the database that contains this table.
     *
     * @return Database
     */
    public function getDatabase()
    {
        return $this->database;
    }

    /**
     * Returns the Database platform.
     *
     * @return PlatformInterface
     */
    public function getPlatform()
    {
        return $this->database ? $this->database->getPlatform() : null;
    }

    /**
     * Returns whether or not code and SQL must be created for this table.
     *
     * Table will be skipped, if return true.
     *
     * @return boolean
     */
    public function isForReferenceOnly()
    {
        return $this->forReferenceOnly;
    }

    /**
     * Returns whether or not to determine if code/sql gets created for this table.
     * Table will be skipped, if set to true.
     * @param boolean $v
     */
    public function setForReferenceOnly($v)
    {
        $this->forReferenceOnly = (Boolean) $v;
    }

    /**
     * Appends XML nodes to passed-in DOMNode.
     *
     * @param \DOMNode $node
     */
    public function appendXml(\DOMNode $node)
    {
        $doc = ($node instanceof \DOMDocument) ? $node : $node->ownerDocument;

        $tableNode = $node->appendChild($doc->createElement('table'));
        $tableNode->setAttribute('name', $this->getCommonName());

        if ($this->getSchema()) {
            $tableNode->setAttribute('schema', $this->getSchema());
        }

        if (null !== $this->getPhpName()) {
            $tableNode->setAttribute('phpName', $this->phpName);
        }

        if (IdMethod::NO_ID_METHOD !== $this->idMethod) {
            $tableNode->setAttribute('idMethod', $this->idMethod);
        }

        if ($this->skipSql) {
            $tableNode->setAttribute('skipSql', 'true');
        }

        if ($this->readOnly) {
            $tableNode->setAttribute('readOnly', 'true');
        }

        if ($this->reloadOnInsert) {
            $tableNode->setAttribute('reloadOnInsert', 'true');
        }

        if ($this->reloadOnUpdate) {
            $tableNode->setAttribute('reloadOnUpdate', 'true');
        }

        if (null !== $this->forReferenceOnly) {
            $tableNode->setAttribute('forReferenceOnly', $this->forReferenceOnly ? 'true' : 'false');
        }

        if ($this->isAbstract) {
            $tableNode->setAttribute('abstract', 'true');
        }

        if ($this->interface) {
            $tableNode->setAttribute('interface', $this->interface);
        }

        if ($this->description) {
            $tableNode->setAttribute('description', $this->description);
        }

        if ($this->namespace) {
            $tableNode->setAttribute('namespace', $this->namespace);
        }

        if ($this->package && !$this->packageOverridden) {
            $tableNode->setAttribute('package', $this->package);
        }

        if ($this->baseClass) {
            $tableNode->setAttribute('baseClass', $this->baseClass);
        }

        if ($this->isCrossRef) {
            $tableNode->setAttribute('isCrossRef', 'true');
        }

        foreach ($this->columns as $col) {
            $col->appendXml($tableNode);
        }

        foreach ($this->foreignKeys as $fk) {
            $fk->appendXml($tableNode);
        }

        foreach ($this->idMethodParameters as $param) {
            $param->appendXml($tableNode);
        }

        foreach ($this->indices as $index) {
            $index->appendXml($tableNode);
        }

        foreach ($this->unices as $unique) {
            $unique->appendXml($tableNode);
        }

        foreach ($this->vendorInfos as $vi) {
            $vi->appendXml($tableNode);
        }
    }

    /**
     * Returns the collection of Columns which make up the single primary
     * key for this table.
     *
     * @return Column[]
     */
    public function getPrimaryKey()
    {
        $pk = array();
        foreach ($this->columns as $col) {
            if ($col->isPrimaryKey()) {
                $pk[] = $col;
            }
        }

        return $pk;
    }

    /**
     * Returns whether or not this table has a primary key.
     *
     * @return boolean
     */
    public function hasPrimaryKey()
    {
        return count($this->getPrimaryKey()) > 0;
    }

    /**
     * Returns whether or not this table has a composite primary key.
     *
     * @return boolean
     */
    public function hasCompositePrimaryKey()
    {
        return count($this->getPrimaryKey()) > 1;
    }

    /**
     * Returns the first primary key column.
     *
     * Useful for tables with a PK using a single column.
     *
     * @return Column
     */
    public function getFirstPrimaryKeyColumn()
    {
        foreach ($this->columns as $col) {
            if ($col->isPrimaryKey()) {
                return $col;
            }
        }
    }

    /**
     * Returns whether or not this table has any auto-increment primary keys.
     *
     * @return boolean
     */
    public function hasAutoIncrementPrimaryKey()
    {
        return null !== $this->getAutoIncrementPrimaryKey();
    }

    /**
     * Returns the auto incremented primary key.
     *
     * @return Column
     */
    public function getAutoIncrementPrimaryKey()
    {
        if (IdMethod::NO_ID_METHOD !== $this->getIdMethod()) {
            foreach ($this->getPrimaryKey() as $pk) {
                if ($pk->isAutoIncrement()) {
                    return $pk;
                }
            }
        }
    }

    /**
     * Returns whether or not there is a cross reference status for this foreign
     * key.
     *
     * @return boolean
     */
    public function getIsCrossRef()
    {
        return $this->isCrossRef;
    }

    /**
     * Alias for Table::getIsCrossRef.
     *
     * @return boolean
     */
    public function isCrossRef()
    {
        return $this->isCrossRef;
    }

    /**
     * Sets a cross reference status for this foreign key.
     *
     * @param boolean $isCrossRef
     */
    public function setIsCrossRef($isCrossRef)
    {
        $this->isCrossRef = (Boolean) $isCrossRef;
    }

    /**
     * Returns whether or not the table has foreign keys.
     *
     * @return boolean
     */
    public function hasForeignKeys()
    {
        return 0 !== count($this->foreignKeys);
    }

    /**
     * Returns whether the table has cross foreign keys or not.
     *
     * @return boolean
     */
    public function hasCrossForeignKeys()
    {
        return 0 !== count($this->getCrossFks());
    }
}
