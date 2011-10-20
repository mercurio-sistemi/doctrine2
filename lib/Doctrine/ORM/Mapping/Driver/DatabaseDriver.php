<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Common\Cache\ArrayCache,
    Doctrine\Common\Annotations\AnnotationReader,
    Doctrine\DBAL\Schema\AbstractSchemaManager,
    Doctrine\DBAL\Schema\SchemaException,
    Doctrine\ORM\Mapping\ClassMetadataInfo,
    Doctrine\ORM\Mapping\MappingException,
    Doctrine\Common\Util\Inflector,
    Doctrine\DBAL\Types\Type;

/**
 * The DatabaseDriver reverse engineers the mapping metadata from a database.
 *
 * @license http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Benjamin Eberlei <kontakt@beberlei.de>
 */
class DatabaseDriver implements Driver
{
    /**
     * @var AbstractSchemaManager
     */
    private $_sm;

    /**
     * @var array
     */
    private $tables = null;

    private $classToTableNames = array();

    /**
     * @var array
     */
    private $manyToManyTables = array();

    /**
     * @var array
     */
    private $classNamesForTables = array();

    /**
     * @var array
     */
    private $fieldNamesForColumns = array();

    /**
     * The namespace for the generated entities.
     *
     * @var string
     */
    private $namespace;

	/**
     * The default repository for the generated entities.
     *
     * @var string
     */
    private $repositoryClassName;

    /**
     * Initializes a new AnnotationDriver that uses the given AnnotationReader for reading
     * docblock annotations.
     *
     * @param AnnotationReader $reader The AnnotationReader to use.
     */
    public function __construct(AbstractSchemaManager $schemaManager)
    {
        $this->_sm = $schemaManager;
    }

    /**
     * Set tables manually instead of relying on the reverse engeneering capabilities of SchemaManager.
     *
     * @param array $entityTables
     * @param array $manyToManyTables
     * @return void
     */
    public function setTables($entityTables, $manyToManyTables)
    {
        $this->tables = $this->manyToManyTables = $this->classToTableNames = array();
        foreach ($entityTables AS $table) {
            $className = $this->getClassNameForTable($table->getName());
            $this->classToTableNames[$className] = $table->getName();
            $this->tables[$table->getName()] = $table;
        }
        foreach ($manyToManyTables AS $table) {
            $this->manyToManyTables[$table->getName()] = $table;
        }
    }

    private function reverseEngineerMappingFromDatabase()
    {
        if ($this->tables !== null) {
            return;
        }

        $tables = array();

        foreach ($this->_sm->listTableNames() as $tableName) {
            $tables[$tableName] = $this->_sm->listTableDetails($tableName);
        }

        $this->tables = $this->manyToManyTables = $this->classToTableNames = array();
        foreach ($tables AS $tableName => $table) {
            /* @var $table Table */
            if ($this->_sm->getDatabasePlatform()->supportsForeignKeyConstraints()) {
                $foreignKeys = $table->getForeignKeys();
            } else {
                $foreignKeys = array();
            }
            $allForeignKeyColumns = array();
            foreach ($foreignKeys AS $foreignKey) {
                $allForeignKeyColumns = array_merge($allForeignKeyColumns, $foreignKey->getLocalColumns());
            }

            $pkColumns = $table->getPrimaryKey()->getColumns();
            sort($pkColumns);
            sort($allForeignKeyColumns);

            if ($pkColumns == $allForeignKeyColumns && count($foreignKeys) == 2 && count($table->getColumns())==count($foreignKeys)) {
                $this->manyToManyTables[$tableName] = $table;
            } else {
                // lower-casing is necessary because of Oracle Uppercase Tablenames,
                // assumption is lower-case + underscore separated.
                $className = $this->getClassNameForTable($tableName);
                $this->tables[$tableName] = $table;
                $this->classToTableNames[$className] = $tableName;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadMetadataForClass($className, ClassMetadataInfo $metadata)
    {
        $this->reverseEngineerMappingFromDatabase();

        if (!isset($this->classToTableNames[$className])) {
            throw new \InvalidArgumentException("Unknown class " . $className);
        }

        $tableName = $this->classToTableNames[$className];

        $metadata->name = $className;
        if($this->getRepositoryClassName()){
        	$metadata->setCustomRepositoryClass($this->getRepositoryClassName());
        }
        $metadata->table['name'] = $tableName;

        $columns = $this->tables[$tableName]->getColumns();
        $indexes = $this->tables[$tableName]->getIndexes();
        try {
            $primaryKeyColumns = $this->tables[$tableName]->getPrimaryKey()->getColumns();
        } catch(SchemaException $e) {
            $primaryKeyColumns = array();
        }

        if ($this->_sm->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            $foreignKeys = $this->tables[$tableName]->getForeignKeys();
        } else {
            $foreignKeys = array();
        }

        $allForeignKeyColumns = array();
        foreach ($foreignKeys AS $foreignKey) {
            $allForeignKeyColumns = array_merge($allForeignKeyColumns, $foreignKey->getLocalColumns());
        }

        $ids = array();
        $fieldMappings = array();
        foreach ($columns as $column) {
            $fieldMapping = array();
            if ($primaryKeyColumns && in_array($column->getName(), $primaryKeyColumns)) {
                $fieldMapping['id'] = true;
            } else if (in_array($column->getName(), $allForeignKeyColumns)) {
                continue;
            }



        	$isPkFk = false;
        	foreach ($foreignKeys as $fk){
        		if(in_array($column->getName(), $fk->getColumns())){
        			$isPkFk = true;
        			break;
        		}
        	}

            $fieldMapping['fieldName'] = $this->getFieldNameForColumn($tableName, $column->getName(), $isPkFk);
            $fieldMapping['columnName'] = $column->getName();
            $fieldMapping['type'] = strtolower((string) $column->getType());

            if ($column->getType() instanceof \Doctrine\DBAL\Types\StringType) {
                $fieldMapping['length'] = $column->getLength();
                $fieldMapping['fixed'] = $column->getFixed();
            } else if ($column->getType() instanceof \Doctrine\DBAL\Types\IntegerType) {
                $fieldMapping['unsigned'] = $column->getUnsigned();
            }
            $fieldMapping['nullable'] = $column->getNotNull() ? false : true;

            if($isPkFk && $fieldMapping['id']){
            	$fieldMapping['associationKey']=true;
            }

            if (isset($fieldMapping['id'])) {
                $ids[] = $fieldMapping;
            } else {
                $fieldMappings[] = $fieldMapping;
            }
        }

        if ($ids) {

        	$idsInt = in_array($ids[0]['type'], array(Type::INTEGER, Type::SMALLINT,Type::BIGINT));

        	$isPkFk = false;
        	foreach ($foreignKeys as $fk){
        		if(!array_diff($primaryKeyColumns, $fk->getColumns())){
        			$isPkFk = true;
        			break;
        		}
        	}

            if (count($ids) == 1 && $idsInt && !$isPkFk) {
                $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
            }

            foreach ($ids as $id) {
                $metadata->mapField($id);
            }
        }

        foreach ($fieldMappings as $fieldMapping) {
            $metadata->mapField($fieldMapping);
        }

        foreach ($this->manyToManyTables AS $manyTable) {
            foreach ($manyTable->getForeignKeys() AS $foreignKey) {
                // foreign  key maps to the table of the current entity, many to many association probably exists
                if (strtolower($tableName) == strtolower($foreignKey->getForeignTableName())) {
                    $myFk = $foreignKey;
                    $otherFk = null;
                    foreach ($manyTable->getForeignKeys() AS $foreignKey) {
                        if ($foreignKey != $myFk) {
                            $otherFk = $foreignKey;
                            break;
                        }
                    }

                    if (!$otherFk) {
                        // the definition of this many to many table does not contain
                        // enough foreign key information to continue reverse engeneering.
                        continue;
                    }

                    $localColumn = current($myFk->getColumns());
                    $associationMapping = array();
                    $associationMapping['fieldName'] = $this->pluralize($this->getFieldNameForColumn($manyTable->getName(), current($otherFk->getColumns()), true));
                    $associationMapping['targetEntity'] = $this->getClassNameForTable($otherFk->getForeignTableName());
                    if (current($manyTable->getColumns())->getName() == $localColumn) { // owing side od inverse side
                        $associationMapping['inversedBy'] = $this->pluralize($this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true));
                        $associationMapping['joinTable'] = array(
                            'name' => strtolower($manyTable->getName()),
                            'joinColumns' => array(),
                            'inverseJoinColumns' => array(),
                        );




                        $fkCols = $myFk->getForeignColumns();
                        $cols = $myFk->getColumns();
                        for ($i = 0; $i < count($cols); $i++) {
                            $associationMapping['joinTable']['joinColumns'][] = array(
                                'name' => $cols[$i],
                                'referencedColumnName' => $fkCols[$i],
                            );
                        }


                        $fkCols = $otherFk->getForeignColumns();
                        $cols = $otherFk->getColumns();
                        for ($i = 0; $i < count($cols); $i++) {
                            $associationMapping['joinTable']['inverseJoinColumns'][] = array(
                                'name' => $cols[$i],
                                'referencedColumnName' => $fkCols[$i],
                            );
                        }
	                    // default indexes for relations with one fk column
						if(count($cols) == 1){
							$associationMapping["indexBy"]=current($fkCols);
						}
                    } else {
                    	$fkCols = $otherFk->getForeignColumns();
                  	 	if(count($fkCols) == 1){
							$associationMapping["indexBy"]=current($fkCols);
						}
                        $associationMapping['mappedBy'] = $this->pluralize($this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true));
                    }

                    $metadata->mapManyToMany($associationMapping);
                    break;
                }
            }
        }

        foreach ($foreignKeys as $foreignKey) {
            $foreignTable = $foreignKey->getForeignTableName();
            $cols = $foreignKey->getColumns();
            $fkCols = $foreignKey->getForeignColumns();
			$pkCols = $this->tables[$foreignTable]->getPrimaryKey()->getColumns();


			if(count($cols)==1){ //
				$localColumn = current($cols);
			}else{
				if(in_array($foreignTable, $cols)){ // lazy entity
					$localColumn = $foreignTable;
				}else{
					$localColumn = current($cols);
				}
			}

            $associationMapping = array();

            $associationMapping['targetEntity'] = $this->getClassNameForTable($foreignTable);

            for ($i = 0; $i < count($cols); $i++) {
                $associationMapping['joinColumns'][] = array(
                    'name' => $cols[$i],
                    'referencedColumnName' => $fkCols[$i],
                );
            }
			// if FKs cols equal to PKs cols then is an one-to-one mapping
			if(!count(array_diff($fkCols, $pkCols)) && !count(array_diff($cols, $primaryKeyColumns)) ){
				$associationMapping['fieldName'] = $this->getFieldNameForColumn($tableName, $localColumn, true);

				$metadata->mapOneToOne($associationMapping);
			}else{
				$associationMapping['fieldName'] = $this->getFieldNameForColumn($tableName, $localColumn, true);
				$associationMapping['inversedBy'] = $this->pluralize($this->getFieldNameForColumn($foreignTable, $tableName, true));
				$metadata->mapManyToOne($associationMapping);
			}
        }


        foreach ($this->tables as $tableCandidate){
        	$candidateTableName = $tableCandidate->getName();
	        if ($this->_sm->getDatabasePlatform()->supportsForeignKeyConstraints()) {
	            $foreignKeysCandidate = $tableCandidate->getForeignKeys();
	        } else {
	        	$foreignKeysCandidate = array();
        	}

        	foreach ($foreignKeysCandidate as $foreignKey){
        		$foreignTable = $foreignKey->getForeignTableName();

        		if($foreignTable == $tableName && !isset($this->manyToManyTables[$candidateTableName])){

        			$fkCols = $foreignKey->getForeignColumns();
	        		$cols = $foreignKey->getColumns();

					$pkCols = $tableCandidate->getPrimaryKey()->getColumns();

	        		$localColumn = current($cols);

        			$associationMapping = array();

            		$associationMapping['targetEntity'] = $this->getClassNameForTable($candidateTableName);

					// if FKs cols equal to PKs cols then is an one-to-one mapping
					if(!count(array_diff($fkCols, $primaryKeyColumns)) && !count(array_diff($pkCols, $cols))){

						$associationMapping['fieldName'] = $this->getFieldNameForColumn($tableCandidate->getName(), $tableCandidate->getName(), true);

						$localColumnCandidate = current($pkCols);
						$associationMapping['mappedBy'] = $this->getFieldNameForColumn($tableCandidate->getName(), $localColumn, true);
						$associationMapping['cascade'] = array('all');

	        			$metadata->mapOneToOne($associationMapping);
					}else{
						$primaryKeyColumnsCandidate = $tableCandidate->getPrimaryKey()->getColumns();

	            		if(count($primaryKeyColumnsCandidate)==1){
	            			$associationMapping['indexBy'] = current($primaryKeyColumnsCandidate);
	            		}else{
	            			$diff = array_diff($primaryKeyColumnsCandidate, $cols);

	            			$associationMapping['cascade'] = array('all');

	            			if(count($diff)==1){
								$associationMapping['indexBy']= current($diff);
	            			}
	            		}

						$associationMapping['fieldName'] = $this->pluralize($this->getFieldNameForColumn($tableCandidate->getName(),  $tableCandidate->getName(), true));
						$associationMapping['mappedBy'] = $this->getFieldNameForColumn($tableCandidate->getName(), $localColumn, true);
						// fix for multiple association with same name
						if($metadata->hasAssociation($associationMapping['fieldName'])){
							$associationMapping['fieldName'] .=ucfirst($associationMapping['mappedBy'] );
						}
						$metadata->mapOneToMany($associationMapping);
					}
        		}
        	}
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isTransient($className)
    {
        return true;
    }

    /**
     * Return all the class names supported by this driver.
     *
     * IMPORTANT: This method must return an array of class not tables names.
     *
     * @return array
     */
    public function getAllClassNames()
    {
        $this->reverseEngineerMappingFromDatabase();

        return array_keys($this->classToTableNames);
    }

    /**
     * Set class name for a table.
     *
     * @param string $tableName
     * @param string $className
     * @return void
     */
    public function setClassNameForTable($tableName, $className)
    {
        $this->classNamesForTables[$tableName] = $className;
    }

    /**
     * Set field name for a column on a specific table.
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $fieldName
     * @return void
     */
    public function setFieldNameForColumn($tableName, $columnName, $fieldName)
    {
        $this->fieldNamesForColumns[$tableName][$columnName] = $fieldName;
    }

    /**
     * Return the mapped class name for a table if it exists. Otherwise return "classified" version.
     *
     * @param string $tableName
     * @return string
     */
    private function getClassNameForTable($tableName)
    {
        if (isset($this->classNamesForTables[$tableName])) {
            return $this->namespace . $this->classNamesForTables[$tableName];
        }

        return $this->namespace . Inflector::classify(strtolower($tableName));
    }

    /**
     * Return the mapped field name for a column, if it exists. Otherwise return camelized version.
     *
     * @param string $tableName
     * @param string $columnName
     * @param boolean $fk Whether the column is a foreignkey or not.
     * @return string
     */
    private function getFieldNameForColumn($tableName, $columnName, $fk = false)
    {
        if (isset($this->fieldNamesForColumns[$tableName]) && isset($this->fieldNamesForColumns[$tableName][$columnName])) {
            return $this->fieldNamesForColumns[$tableName][$columnName];
        }

        $columnName = strtolower($columnName);

        // Replace _id if it is a foreignkey column
        if ($fk) {
            $columnName = str_replace('_id', '', $columnName);
        }
        return Inflector::camelize($columnName);
    }

    /**
     * Set the namespace for the generated entities.
     *
     * @param string $namespace
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }
	/**
     * Set the repository for the generated entities.
     *
     * @param string $repositoryClassName
     * @return void
     */
    public function setRepositoryClassName($repositoryClassName)
    {
        $this->repositoryClassName = $repositoryClassName;
    }

	/**
     * Return the repository for the generated entities.
     *
     * @return string
     */
    public function getRepositoryClassName()
    {
        return $this->repositoryClassName;
    }
	public function pluralize($tableName) {
    	if(substr($tableName, -1)=="a"){
    		if(substr($tableName, -2)=="cia"){
    			return substr($tableName, 0,-2)."e";
    		}
    		if(substr($tableName, -2)=="ia"){
    			return substr($tableName, 0,-2)."ie";
    		}
    		if(substr($tableName, -2)=="ca"){
    			return substr($tableName, 0,-1)."he";
    		}
    		return substr($tableName, 0,-1)."e";
    	}elseif(substr($tableName, -1)=="o"){
    		if(substr($tableName, -2)=="io"){
    			return substr($tableName, 0,-1);
    		}
    		if(substr($tableName, -2)=="co"){
    			return substr($tableName, 0,-1)."hi";
    		}
    		return substr($tableName, 0,-1)."i";
    	}elseif(substr($tableName, -1)=="e"){
    		return substr($tableName, 0,-1)."i";
    	}
    	return "many".ucfirst($tableName);
    }
}