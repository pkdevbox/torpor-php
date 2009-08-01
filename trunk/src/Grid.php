<?PHP
// $Rev$
// TODO: phpdoc
// TODO: Callbacks?
class Grid extends PersistableContainer implements Iterator
{
	private $_columns = array();

	protected function _getColumns(){ return( $this->_columns ); }
	protected function _getColumnNames(){ return( array_keys( $this->_getColumns() ) ); }
	public function columnNames(){ return( $this->_getColumnNames() ); }
	public function hasColumn( $columnName ){
		if( $columnName instanceof Column ){
			$columnName = $columnName->_getObjName();
		}
		return( in_array( $this->Torpor()->makeKeyName( $columnName ), $this->_getColumnNames() ) );
	}

	public function Column( $columnName ){ return( $this->_getColumn( $columnName ) ); }
	public function _getColumn( $columnName ){
		$columnName = $this->Torpor()->makeKeyName( $columnName );
		if( !$this->hasColumn( $columnName ) ){
			$this->throwException( $columnName.' is not a valid column on '.$this->_getObjName() );
		}
		$columns = $this->_getColumns();
		$column = $columns{ $columnName };
		return( $column );
	}

	public function addColumn( Column $column ){
		if( $this->hasColumn( $column ) ){
			$this->throwException( 'Duplicate Column '.$column->_getObjName().' on Grid '.$this->_getObjName() );
		}
		$column->setGrid( $this );
		$this->_columns{ strtoupper( $column->_getObjName() ) } = $column;
	}

	public function removeColumn( $columnName ){
		$return = false;
		if( !$this->hasColumn( $columnName ) ){
			trigger_error( $columnName.' is not a member of this '.__CLASS__, E_NOTICE );
		} else {
			if( $columnName instanceof Column ){
				$columnName = $columnName->_getObjName();
			}
			$columns = $this->_getColumns();
			$columns{ $columnName }->setGrid(); // Sets to null
			unset( $columns{ $columnName } );
			$return = true;
		}
		return( $return );
	}

	public function primaryKey(){
		$keyColumns = false;
		$primaryKeys = $this->Torpor()->primaryKeyForGrid( $this );
		if( $primaryKeys ){
			if( !is_array( $primaryKeys ) ){
				$keyColumns = $this->_getColumn( $primaryKeys );
			} else {
				$keyColumns = array();
				foreach( $primaryKeys as $keyName ){
					$keyColumns[] = $this->_getColumn( $keyName );
				}
			}
		}
		return( $keyColumns );
	}

	public function canLoad(){
		$pass = true;
		$allKeys = $this->Torpor()->allKeysForGrid( $this );
		foreach( $allKeys as $key ){
			$pass = false;
			if( is_array( $key ) ){
				$pass = true;
				foreach( $key as $keyColumn ){
					if( !$this->Column( $keyColumn )->hasData() ){
						$pass = false;
						break;
					}
				}
			} else {
				$pass = $this->Column( $key )->hasData();
			}
			if( $pass ){
				break;
			}
		}
		return( $pass );
	}

	public function Load( $refresh = false ){
		if(
			$this->canLoad()
			&& ( !$this->isLoaded() || $refresh )
		){
			if( false ){
			// TODO: Actually get the data
			// Will need to use grid name and columns to construct fetch
			// then walk through columns doing setData() routines.
			// Includes doing the isKey() checks on the columns to come
			// up with the binding WHERE clause.
			// How does the selection with criteria work?  That should
			// probably be part of the Torpor factory classes using get(Grid)From(X)
			// instead of the new (Grid) approach, as the blank slate.
			// if( $dataMembers = $torpor->_getDataForGrid() ){}
				$this->_setLoaded();
				// TODO: Walk through the columns and look for data, or walk through the
				// data and look for columns?
				foreach( $this->_getColumns() as $column ){
					$newData = 'imagine_a_database_value_here';
					if( $column->hasData() && $column->getData() != $newData ){
						if( !$reset || $this->Torpor()->overwriteOnLoad() ){
							trigger_error( 'Overwriting user-data during load of column '.$column->_getObjName().' on grid '.$this->_getObjName(), E_USER_WARNING );
						}
					}
					$column->setLoadData( $newData );
				}
			} else {
				// TODO: If we supposedly can load, and we haven't found any data,
				// then a couple of things are possible.  1: the criteria provided
				// is new, and not intended to actually find something.  2: The load
				// failed.  Given the possibilities of 1:, we probably shouldn't do
				// anything.  The user documentation will need to include information
				// about checking for the load status.
				// As an alternative, it may be possible that any of the Torpor
				// factory classes which getXFromY will return bool(false) instead of
				// an empty grid, avoiding problems in the first place.
			}
		}
		return( $this->isLoaded() );
	}
	public function Reset(){
		$return = false;
		if( $this->isLoaded() && $this->isDirty() ){
			foreach( $this->_getColumns() as $column ){
				$column->Reset();
			}
			$this->_setDirty( false );
			$return = true;
		}
		return( $return );
	}

	public function Publish( $force = false ){ return( $this->Persist( $force ) ); }
	public function Persist( $force = false ){
		if( $this->isDirty() || $force ){
			$this->_setDirty( false );
		}
		return( $this->isDirty() );
	}

	// Abstract all getter & setter methods.
	public function __call( $function, $arguments ){
		$return = null;

		// Abort early if column name is being used in function context.
		if( $this->hasColumn( $function ) ){
			return( $this->Column( $function ) );
		}

		$operation = $this->Torpor()->detectOperation( $function );
		if( $operation === false ){
			$this->throwException( 'Unkown or unsupported method "'.$function.'" requested' );
		}
		$noun = $this->Torpor()->makeKeyName( substr( $function, strlen( $operation ) ) );
		if( !$this->hasColumn( $noun ) ){
			// Not attempting a column operation, see if this is something we're looking for
			// larger factory operations.
			// get<Target>
			// get<Target>Set
			// new<Target>
			// new<Target>Set ?
			if(
				$operation == Torpor::OPERATION_SET
				&& $arguments[0] instanceof Grid
				&& $this->Torpor()->canReference( $this, $arguments[0], $noun )
			){
				$incomingGrid = $arguments[0];
				$references = array();
				if( $incomingGrid->_getObjName() == $noun ){ // Direct relationship
					$references = $this->Torpor()->referenceKeysBetween( $this, $incomingGrid, Torpor::NON_ALIAS_KEYS );
				} else { // Aliased relationship
					$references = $this->Torpor()->aliasReferenceKeysBetween( $this, $incomingGrid, $noun );
				}
				foreach( $references as $sourceColumnName => $targetColumnName ){
					$targetColumn = $incomingGrid->Column( $targetColumnName );

					// Get data of data can be got.
					if( !$targetColumn->hasData() ){
						$targetColumn->Load();
					}

					// If the target column still doesn't have any data, look to see if we can
					// do dynamic linking.
					if( !$targetColumn->hasData() ){
						if(
							$targetColumn->isGeneratedOnPublish()
							&& $this->Torpor()->linkUnpublishedReferenceColumns()
						){
							$this->Column( $sourceColumnName )->linkToColumn(
								$targetColumn,
								$this->Torpor()->perpetuateAutoLinks()
							);
						} else {
							$this->throwException( 'Insufficient reference data: no values in '.$targetColumnName.' when trying to set '.$this->_getObjName().'->'.$sourceColumnName.' from '.$incomingGrid->_getObjName() );
						}
					} else {
						$this->$sourceColumnName = $incomingGrid->$targetColumnName;
					}
				}
				return( true );
			} else if( stripos( $function, Torpor::OPERATION_MOD_FROM ) && $this->Torpor()->can( $function ) ){
				return( $this->Torpor()->$function( $this ) );
			} else {
				$torporCall = $function.Torpor::OPERATION_MOD_FROM.$this->_getObjName();
				if( $this->Torpor()->can( $torporCall ) ){
					return( $this->Torpor()->$torporCall( $this ) );
				}
			}
			$this->throwException( $noun.' does not exist as a member or method of this class' );
		}

		$column = $this->_getColumn( $noun );
		if( !( $column instanceof Column ) ){
			$this->throwException( $noun.' is not a valid Column object' );
		}
		switch( $operation ){
			case Torpor::OPERATION_IS:
				if( $column->getType() == Column::TYPE_BOOL ){
					$return = $column->getData();
				} else {
					trigger_error( $noun.' is not of type '.Column::TYPE_BOOL, E_USER_ERROR );
				}
				break;
			case Torpor::OPERATION_GET:
				if( !$column->isLoaded() && $this->isLoaded() ){
					$this->throwException( 'Unloaded Column '.$noun.' in loaded Grid '.$this->_getObjName() );
				}
				$return = $column->getData(); // Will automatically load the grid as necessary.
				break;
			case Torpor::OPERATION_SET:
				// alias set<Column>() == set<Column>( true ); for BOOL types
				if( count( $arguments ) == 0 && $column->getType() == Column::TYPE_BOOL ){
					$return = $column->setData( true );
				} else {
					// TODO: Turn the 'setData' string into a constant?
					$return = call_user_func_array( array( $column, 'setData' ), $arguments );
				}
				$this->_setDirty( $this->isDirty() || $column->isDirty() );
				break;
			default:
				$this->throwException( 'Unrecognized operation '.$operation );
				break;
		}
		return( $return );
	}

	public function __get( $name ){
		$function = 'get'.$name;
		return( $this->$function() );
	}

	public function __set( $name, $value ){
		$function = 'set'.$name;
		return( $this->$function( $value ) );
	}

	// Iterator interface implementation for accessing columns
	public function rewind(){ reset( $this->_columns ); }
	public function current(){ return( current( $this->_columns ) ); }
	public function key(){ return( key( $this->_columns ) ); }
	public function next(){ return( next( $this->_columns ) ); }
	public function valid(){ return( $this->current() !== false ); }
}
?>
