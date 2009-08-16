<?PHP
// $Rev$
class CriteriaSet implements Iterator {
	const TYPE_AND = 'AND';
	const TYPE_OR  = 'OR';
	// TODO: TYPE_XOR?

	private $_criteria = array();
	private $_type = self::TYPE_AND;

	public function CriteriaSet( $type ){
		if( !empty( $type ) ){ $this->setType( $type ); }
		foreach( array_slice( func_get_args(), 1 ) as $criterion ){
			$this->addCriteria( $criterion );
		}
	}

	public function getType(){ return( $this->_type ); }
	public function setType( $type ){
		switch( $type ){
			case self::TYPE_AND:
			case self::TYPE_OR:
				break;
			default:
				throw( new TorporException( 'Unrecognized or unsupported type "'.$type.'"' ) );
				break;
		}
		return( $this->_type = $type );
	}

	public function getCriteria(){ return( $this->_criteria ); }

	public function length(){ return( $this->count() ); }
	// Using local access to variables instead of facade through getCriteria for
	// performance and security (want non-copy access to the array, but don't wan't
	// to make getCriteria() return by reference, and it would be just silly to
	// create our own internal getCriteriaByReference() when this isn't an extendable
	// function.
	public function count(){ return( count( $this->_criteria ) ); }

	// "criteria" has been used in the singular (over "criterion") for > 50 years.  Deal with it.
	public function addCriterion( $criteria ){ return( $this->addCriteria( $criteria ) ); }
	public function addCriteria( $criteria ){
		if( in_array( $criteria, $this->_critera, true ) ){ return( false ); }
		if( !( $criteria instanceof Criteria ) && !( $criteria instanceof CriteriaSet ) ){
			throw( new TorporException( 'Argument must be one of Criteria or CriteriaSet, '
				.( is_object( $criteria ) ? get_class( $criteria ) : gettype( $criteria ) ).' given' ) );
		}
		$this->_criteria[] = $criteria;
		return( true );
	}

	// Iterator interface implementation for accessing grids
	public function rewind(){ reset( $this->_criteria ); }
	public function current(){ return( current( $this->_criteria ) ); }
	public function key(){ return( key( $this->_criteria ) ); }
	public function next(){ return( next( $this->_criteria ) ); }
	public function valid(){ return( $this->current() !== false ); }
}

?>
