<?php
/**
 * (c) 2010 phpgrease.net
 *
 * For licensing terms, plese see license.txt which should distribute with this source
 *
 * @package Pandra
 * @author Michael Pearson <pandra-support@phpgrease.net>
 */

/**
 * @abstract
 */
class PandraSuperColumnFamily extends PandraColumnFamily {

    /* @var string magic get/set prefix for Super Columns */
    const _columnNamePrefix = 'super_';

    /**
     * Helper function to add a Super Column instance to this Super Column Family
     * addSuper overrides the parent container reference in the object instance
     * To add the same supercolumn instance to multiple columnfamilies, use object clones
     * instead.
     * @param PandraSuperColumn $scObj
     * @return PandraSuperColumn
     */
    public function addSuper(PandraSuperColumn $scObj) {
        $superName = $scObj->getName();
        $scObj->setParent($this);
        $this->_columns[$superName] = $scObj;

        return $this->getColumn($superName);
    }

    /**
     * Define a new named SuperColumn, anologous to ColumnFamily->addColumn
     * The only real difference between addColumn and addSuper in a SuperColumn
     * context, is addColumn will not overwrite the column with a new named instance
     * @param string $superName super column name
     * @return PandraSuperColumn reference to created column
     */
    public function addColumn($superName) {
        if (!array_key_exists($superName, $this->_columns)) {
            $this->_columns[$superName] = new PandraSuperColumn($superName, $this);
        }
        return $this->getColumn($superName);
    }

    /**
     * getColumn alias (context helper)
     * @param <type> $superName
     * @return <type>
     */
    public function getSuper($superName) {
        return $this->getColumn($superName);
    }

    public function save($consistencyLevel = NULL) {

        if (!$this->isModified()) return FALSE;

        $ok = $this->pathOK();

        if ($ok) {

            // Deletes the entire columnfamily by key
            if ($this->isDeleted()) {
                $columnPath = new cassandra_ColumnPath();
                $columnPath->column_family = $this->getName();

                $ok = PandraCore::deleteColumnPath($this->getKeySpace(), $this->getKeyID(), $columnPath, NULL, PandraCore::getConsistency($consistencyLevel));
                if (!$ok) $this->registerError(PandraCore::$lastError);

            } else {
                foreach ($this->_columns as $colName => $superColumn) {
                    $ok = $superColumn->save();
                    if (!$ok) {
                        $this->registerError(PandraCore::$lastError);
                        break;
                    }
                }
            }
        }

        return $ok;
    }

    /**
     * Loads an entire columnfamily by keyid
     * @param string $keyID row key
     * @param bool $colAutoCreate create columns in the object instance which have not been defined
     * @param int $consistencyLevel cassandra consistency level
     * @return bool loaded OK
     */
    public function load($keyID = NULL, $colAutoCreate = NULL, $consistencyLevel = NULL) {

        if ($keyID === NULL) $keyID = $this->getKeyID();

        $ok = $this->pathOK($keyID);

        $this->setLoaded(FALSE);

        if ($ok) {

            $autoCreate = $this->getAutoCreate($colAutoCreate);

            // if autocreate is turned on, get everything
            if ($autoCreate) {
                $result = PandraCore::getCFSlice($this->getKeySpace(), $keyID, $this->getName(), NULL, PandraCore::getConsistency($consistencyLevel));
            } else {
                // otherwise by defined columns (slice query)
                $result = PandraCore::getCFSliceMulti($this->getKeySpace(), array($keyID), $this->getName(), array_keys($this->_columns), NULL, $consistencyLevel);
                $result = $result[$keyID];
            }

            if ($result !== NULL) {
                $this->init();
                foreach ($result as $superColumn) {
                    $sc = $superColumn->super_column;

                    if ($this->addSuper(new PandraSuperColumn($sc->name))->populate($sc->columns, $autoCreate)) {
                        $this->setLoaded(TRUE);
                    } else {
                        $this->setLoaded(FALSE);
                        break;
                    }
                }

                if ($this->isLoaded()) $this->setKeyID($keyID);

            } else {
                $this->registerError(PandraCore::$lastError);
            }
        }
        return ($ok && $this->isLoaded());
    }

    /**
     * Populates container object (ColumnFamily, ColumnFamilySuper or SuperColumn)
     * @param mixed $data associative string array, array of cassandra_Column's or JSON string of key => values.
     * @return bool column values set without error
     */
    public function populate($data, $colAutoCreate = NULL) {
        if (is_string($data)) {
            $data = json_decode($data, TRUE);
        }

        if (is_array($data) && count($data)) {

            foreach ($data as $idx => $colValue) {

                // Allow named SuperColumns to be populated into this CF
                if ($colValue instanceof PandraSuperColumn) {
                    if ($this->getAutoCreate($colAutoCreate) || array_key_exists($idx, $this->_columns)) {
                        $this->_columns[$idx] = $colValue;
                    }
                } else {
                    if ($this->getAutoCreate($colAutoCreate) || array_key_exists($idx, $this->_columns)) {
                        $this->addSuper(new PandraSuperColumn($idx), $this)->populate($colValue);
                    }
                }
            }
        } else {
            return FALSE;
        }

        return empty($this->errors);
    }
}
?>