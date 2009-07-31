<?PHP
// $Rev$
// TODO: phpdoc
// TODO: Callbacks?
class Grid extends PersistableContainer
{
	const OPERATION_GET = 'get';
	const OPERATION_IS  = 'is';
	const OPERATION_NEW = 'new';
	const OPERATION_SET = 'set';

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
					if( !$keyColumn->hasData() ){
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
			// if( $dataMembers = $torpor->_getDataForGrid() ){
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

	// TODO: Need a way to abstract the building of a WHERE condition based on the keys.
	// There will need to be a literal DataAccessObject responsible for translating between
	// this and wherever it's being saved, and will be a member of Torpor.

	// Abstract all getter & setter methods.
	public function __call( $func, $args ){
		$return = null;
		// TODO: Decide on a common scheme of strto(upper|lower)
		$func = strtolower( $this->Torpor()->makeKeyName( $func ) );
		$operation = ( stripos( $func, self::OPERATION_IS ) === 0 ? self::OPERATION_IS : strtolower( substr( $func, 0, 3 ) ) );
		// TODO: Evalutate operations first?

		// TODO: make use of the 'is' prefix when testing bools, and the convention of setBoolColumn()
		// with no arguments defaulting the value to true.

		// TODO: How to handle factory methods based on identified foreign key
		// criteria?  Should always be fetching a collection (which may have a single member,
		// which will need to have convenience methods for as well)
		$funcRemainder = strtoupper( substr( $func, strlen( $operation ) ) );
		if( !$this->hasColumn( $funcRemainder ) ){
			// Not attempting a column operation, see if this is something we're looking for
			// larger factory operations.
			// get<Target>
			// get<Target>Set
			// new<Target>
			// new<Target>Set ?
			if(
				$operation == self::OPERATION_SET
				&& $args[0] instanceof Grid
				&& $this->Torpor()->canReference( $this, $args[0], $funcRemainder )
			){
				$incomingGrid = $args[0];
				$references = array();
				if( $incomingGrid->_getObjName() == $funcRemainder ){ // Direct relationship
					$references = $this->Torpor()->referenceKeysBetween( $this, $incomingGrid, Torpor::NON_ALIAS_KEYS );
				} else { // Aliased relationship
					$references = $this->Torpor()->aliasReferenceKeysBetween( $this, $incomingGrid, $funcRemainder );
				}
				foreach( $references as $sourceColumnName => $targetColumnName ){
					$targetColumn = $incomingGrid->Column( $targetColumnName );
					// Get data of data can be got.
					if( !$targetColumn->hasData() ){ $targetColumn->Load(); }
					// If the target column still doesn't have any data, look to see if we can
					// do dynamic linking.
					if( !$targetColumn->hasData() ){
						// TODO: Look at Torpor settings to determine if all linked relationship
						// should be established, and whether they should persist.
						if( $targetColumn->isGeneratedOnPublish() ){
							$this->Column( $sourceColumnName )->linkToColumn( $targetColumn );
						} else {
							$this->throwException( 'Insufficient reference data: no values in '.$targetColumnName.' when trying to set '.$this->_getObjName().'->'.$sourceColumnName.' from '.$incomingGrid->_getObjName() );
						}
					} else {
						$this->$sourceColumnName = $incomingGrid->$targetColumnName;
					}
				}
				return( true );
			} else if( stripos( $func, Torpor::OPERATION_MOD_FROM ) && $this->Torpor()->can( $func ) ){
				return( $this->Torpor()->$func( $this ) );
			} else {
				$torporCall = $func.Torpor::OPERATION_MOD_FROM.$this->_getObjName();
				if( $this->Torpor()->can( $torporCall ) ){
					return( $this->Torpor()->$torporCall( $this ) );
				}
			}
			// TODO: set<Target> where <Target> is a grid (or grid alias), e.g.,
			// $order->setUser( $user ) or $order->setCustomer( $user ).  Especially
			// necessary for this to be handled internally (rather than individual object
			// extension by the end-user) since we will need to keep track of the unsaved
			// object dependency tree, in the event that $user (from the above example)
			// has not yet been published, but should be in the event that $order is (and
			// in fact would need to be saved first, in order to propagate the relaionship
			// defining keeys up to $order prior to its being published)
			$this->throwException( $funcRemainder.' does not exist as a member or method of this class' );
		}
		// TODO: What to do about longer operation names, and especially the treatment
		// of collections? (for add/remove operations)  And collections in general, with their
		// mapping grids?
		// Collections should be stored as a reference to a result set which corresponds to this
		// criteria - which means that we now need concepts of 2 kinds of relationships:
		// -- Backreference ID (remote record contains a reference to this item's ID)
		// -- Mapping table (an intermediate table contains a reference to both this item, and
		//    the remote object's ID)
		// When belonging to either of these types of collections, the addition of any new
		// element or setting of any interior data needs to verify that the key relationships
		// stay intact (adding an object to the collection automatically sets the key ID)
		// and throw nice big warnings/errors otherwise.  This is going to be the deep
		// magic of Torpor that really sells it; if I can say User::getOrders().
		// TODO: The main Torpor instance should own and maintain the mapping table on its
		// own, making sure that any time one of the sub members is deleted or published that
		// the appropriate changes also take place inside that map.  This still needs to be
		// accessible to the user without jumping through too many hoops.
		$column = $this->_getColumn( $funcRemainder );
		if( !( $column instanceof Column ) ){
			$this->throwException( $funcRemainder.' is not a valid Column object' );
		}
		switch( $operation ){
			case self::OPERATION_IS:
				if( $column->getType() == Column::TYPE_BOOL ){
					$return = $column->getData();
				} else {
					trigger_error( $funcRemainder.' is not of type '.Column::TYPE_BOOL, E_USER_ERROR );
				}
				break;
			case self::OPERATION_GET:
				if( !$column->isLoaded() && $this->isLoaded() ){
					$this->throwException( 'Unloaded Column '.$funcRemainder.' in loaded Grid '.$this->_getObjName() );
				}
				$return = $column->getData(); // Will automatically load the grid as necessary.
				break;
			case self::OPERATION_SET:
				// alias set<Column>() == set<Column>( true ); for BOOL types
				if( count( $args ) == 0 && $column->getType() == Column::TYPE_BOOL ){
					$return = $column->setData( true );
				} else {
					$return = $column->setData( array_shift( $args ) );
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
		$func = 'get'.$name;
		return( $this->$func() );
	}

	public function __set( $name, $value ){
		$func = 'set'.$name;
		return( $this->$func( $value ) );
	}
}
?>
