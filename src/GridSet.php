<?PHP
// $Rev$
class GridSet extends PersistableContainer implements Iterator {
	const ADJECTIVE_FIRST = 'first';
	const ADJECTIVE_NEXT  = 'next';

	private $_grids = array();
	private $_sourceGrid = null;
	private $_sourceCriteria = null;
	// TODO:
	//X1. Maintain a collection of grids.
	//X2. Provide access to those grids via enumeration & array mechanisms
	// 3. Store references to originating criterion
	//    ...in the form of a source Grid object
	//    ...in the form of a master Criteria object
	// 4. Provide "add" mechanisms to push new Grid members onto the end
	//    and set all deterministic criteria (see no. 3) on the incoming
	//    Grid (unless otherwise requested) in order to maintain the correct
	//    reference associations.

	public function getSourceGrid(){ return( $this->_sourceGrid ); }
	public function getSourceCriteria(){ return( $this->_sourceCriteria ); }

	// Generic accessors <verb> and <verb><adjective> can be combined
	// with <verb>[<adjective>]<noun> via the __call interface, making it
	// possible to add<Grid> and getFirst<Grid>, etc.
	// Returns true if a grid is successfully added to the collection.  The only
	// ways this will not be true is if we thow an exception or if the grid already
	// exists within the collection (only detected by looking to see if it's the
	// exact same object, no other de-deplication is being done; that's up to the
	// caching engine).
	public function add( Grid $grid, $setGridCriteria = true ){
		if( !is_null( $this->gridType() ) && !$this->checkNoun( $grid->_getObjName() ) ){
			$this->Torpor()->throwException( 'Grid type mismatch, "'.$grid->_getObjName().'" cannot be added to collection of type '.$this->gridType() );
		}
		$return = false;
		if( !in_array( $grid, $this->_grids, true ) ){
			// TODO: Add deterministic criteria (if we have any) which maps the source
			// grid relationship or criteria onto the incoming grid object.
			if( $setGridCriteria ){
				if( !is_null( $this->getSourceGrid() ) ){
				} else if( !is_null( $this->getSourceCriteria() ) ){
				}
			}
			$this->_grids[] = $grid;
			$return = true;
		}
		return( $return );
	}

	public function recordType(){ return( $this->gridType() ); }
	public function gridType(){
		$return = null;
		if( $this->gridCount() > 0 ){
			$return = $this->_grids[0]->_getObjType();
		}
		return( $return );
	}

	protected function checkNoun( $noun ){
		$pass = false;
		if(
			!is_null( $noun )
			&& in_array(
				strtolower( $noun ),
				array(
					'grid',   // Used only once, right here; should they still be
					'record', // turned into constants?
					strtolower( $this->gridType() )
				)
			)
		){
			$pass = true;
		}
		return( $pass );
	}

	// These are essentially facades and aliases to the built-in iterator
	// interfaces which we've already abstracted on top of $_grids, but are
	// provided fo convenience and ease of documentation.
	public function getFirst(){
		$this->rewind();
		return( $this->current() );
	}
	public function getCurrent(){ return( $this->current() ); }
	public function getNext(){ return( $this->next() ); }

	public function recordCount(){ return( $this->gridCount() ); }
	public function gridCount(){ return( count( $this->_grids ) ); } 

	// Used so we can have descriptive names, such as add<Grid>, etc.
	public function __call( $func, $args ){
		$return = null;
		// add<Grid>
		// getFirst<Grid>
		// getNext<Grid>
		$func = strtolower( $this->Torpor()->makeKeyName( $func ) );
		$operation = substr( $func, 0, Torpor::COMMON_OPERATION_LENGTH );
		$funcRemainder = substr( $func, strlen( $operation ) );
		switch( $operation ){
			case Torpor::OPERATION_ADD:
				if( !is_null( $this->gridType() ) && !$this->checkNoun( $funcRemainder ) ){
					$this->Torpor()->throwExcetion( 'Unrecognized operation or incorrect grid type "'.$funcRemainder.'"' );
				}
				if( count( $args ) > 1 ){
					$return = 0;
					foreach( $args as $grid ){
						if( $this->add( $grid ) ){
							$return++;
						}
					}
				} else {
					$return = $this->add( array_shift( $args ) );
				}
				break;
			case Torpor::OPERATION_GET:
				// See if we have an adjective
				$adjective = false;
				if( strpos( $funcRemainder, self::ADJECTIVE_FIRST ) === 0 ){
					$adjective = self::ADJECTIVE_FIRST;
				} else if( strpos( $funcRemainder, self::ADJECTIVE_NEXT ) == 0 ){
					$adjective = self::ADJECTIVE_NEXT;
				}
				if( $adjective !== false ){
					$funcRemainder = substr( $funcRemainder, strlen( $adjective ) );
				}
				if( !$this->checkNoun( $funcRemainder ) ){
					$this->Torpor()->throwExcetion( 'Unrecognized operation or incorrect grid type "'.$funcRemainder.'"' );
				}
				switch( $adjective ){
					case self::ADJECTIVE_FIRST:
						$return = $this->getFirst();
						break;
					case self::ADJECTIVE_NEXT:
						$return = $this->getNext();
						break;
					default:
						$return = $this->getCurrent();
						break;
				}
				break;
		}
	}

	// Iterator interface implementation for accessing columns
	public function rewind(){ reset( $this->_grids ); }
	public function current(){ return( current( $this->_grids ) ); }
	public function key(){ return( key( $this->_grids ) ); }
	public function next(){ return( next( $this->_grids ) ); }
	public function valid(){ return( $this->current() !== false ); }
}

?>
