<?PHP
// $Rev$
// TODO: phpdoc
class CriteriaBase {};

class Criteria extends CriteriaBase {
	// Modifiers
	const NOT = 'NOT_';
	const CRITERIA = 'CRITERIA';
	const COLUMN = '_COLUMN';

	// Criteria Types
	const TYPE_BETWEEN            = 'BETWEEN';
	const TYPE_BETWEEN_COLUMN     = 'BETWEEN_COLUMN';
	const TYPE_NOT_BETWEEN        = 'NOT_BETWEEN';
	const TYPE_NOT_BETWEEN_COLUMN = 'NOT_BETWEEN_COLUMN';

	const TYPE_CONTAINS            = 'CONTAINS';
	const TYPE_CONTAINS_COLUMN     = 'CONTAINS_COLUMN';
	const TYPE_NOT_CONTAINS        = 'NOT_CONTAINS';
	const TYPE_NOT_CONTAINS_COLUMN = 'NOT_CONTAINS_COLUMN';

	const TYPE_CUSTOM            = 'CUSTOM';
	const TYPE_CUSTOM_COLUMN     = 'CUSTOM_COLUMN';
	const TYPE_NOT_CUSTOM        = 'NOT_CUSTOM';
	const TYPE_NOT_CUSTOM_COLUMN = 'NOT_CUSTOM_COLUMN';

	const TYPE_ENDSWITH            = 'ENDSWITH';
	const TYPE_ENDSWITH_COLUMN     = 'ENDSWITH_COLUMN';
	const TYPE_NOT_ENDSWITH        = 'NOT_ENDSWITH';
	const TYPE_NOT_ENDSWITH_COLUMN = 'NOT_ENDSWITH_COLUMN';

	const TYPE_EQUALS            = 'EQUALS';
	const TYPE_EQUALS_COLUMN     = 'EQUALS_COLUMN';
	const TYPE_NOT_EQUALS        = 'NOT_EQUALS';
	const TYPE_NOT_EQUALS_COLUMN = 'NOT_EQUALS_COLUMN';

	const TYPE_GREATERTHAN            = 'GREATERTHAN';
	const TYPE_GREATERTHAN_COLUMN     = 'GREATERTHAN_COLUMN';
	const TYPE_NOT_GREATERTHAN        = 'NOT_GREATERTHAN';
	const TYPE_NOT_GREATERTHAN_COLUMN = 'NOT_GREATERTHAN_COLUMN';

	const TYPE_IN            = 'IN';
	const TYPE_IN_COLUMN     = 'IN_COLUMN';
	const TYPE_NOT_IN        = 'NOT_IN';
	const TYPE_NOT_IN_COLUMN = 'NOT_IN_COLUMN';

	const TYPE_IN_SET     = 'IN_SET';
	const TYPE_NOT_IN_SET = 'IN_SET';

	const TYPE_LESSTHAN            = 'LESSTHAN';
	const TYPE_LESSTHAN_COLUMN     = 'LESSTHAN_COLUMN';
	const TYPE_NOT_LESSTHAN        = 'NOT_LESSTHAN';
	const TYPE_NOT_LESSTHAN_COLUMN = 'NOT_LESSTHAN_COLUMN';

	const TYPE_PATTERN = 'PATTERN';

	const TYPE_STARTSWITH            = 'STARTSWITH';
	const TYPE_STARTSWITH_COLUMN     = 'STARTSWITH_COLUMN';
	const TYPE_NOT_STARTSWITH        = 'NOT_STARTSWITH';
	const TYPE_NOT_STARTSWITH_COLUMN = 'NOT_STARTSWITH_COLUMN';

	private $_not = false;
	private $_columnTarget = false;
	private $_inclusive = false;
	private $_caseInsensitive = false;

	private $_gridName;
	private $_columnName;
	private $_type;
	private $_baseType;
	private $_custom;
	private $_args = array();
	private $_columnArgs = array();

	public function __construct( /* ( ( $gridName, $columnName ) | $columnObject ), $type (, ... ) */){
		$args = func_get_args();
		$container = array_shift( $args );
		if( $container instanceof Column ){
			$this->setGridName( $container->Grid() );
			$this->setColumnName( $container );
		} else if( $container && count( $args ) ){
			$this->setGridName( $container );
			$this->setColumnName( array_shift( $args ) );
		}

		$type = '';
		if( get_class( $this ) != __CLASS__ ){
			// This destroys any underscore separators
			$objType = Torpor::makeKeyName( get_class( $this ) );
			if( strpos( $objType, self::CRITERIA ) === 0 ){
				$objType = substr( $objType, strlen( self::CRITERIA ) );
				$typeKeyNames = array_map( array( 'Torpor', 'makeKeyName' ), self::getValidTypes() );
				if( !in_array( $objType, $typeKeyNames ) ){
					trigger_error( 'Unrecognized Criteria descendant "'.$objType.'", cannot auto-detect type from name', E_USER_WARNING );
					$type = array_shift( $args );
				} else {
					$objNot = Torpor::makeKeyName( self::NOT );
					$objColumn = Torpor::makeKeyName( self::COLUMN );
					if( strpos( $objType, $objNot ) === 0 ){
						$type.= self::NOT;
						$objType = substr( $objType, strlen( $objNot ) );
					}
					if( $pos = strrpos( $objType, $objColumn ) ){
						$type.= substr( $objType, 0, $pos ).self::COLUMN;
					} else {
						$type.= $objType;
					}
				}
			} else {
				$type = array_shift( $args );
			}
		} else {
			$type = array_shift( $args );
		}
		if( !empty( $type ) ){
			$this->setType( $type );
		}
		if( count( $args ) && !is_null( $this->getType() ) ){
			$this->processArgs( $args );
		}
	}

	public function getGridName(){ return( $this->_gridName ); }
	public function setGridName( $gridName ){
		return( $this->_gridName = Torpor::containerKeyName( $gridName ) );
	}

	public function getColumnName(){ return( $this->_columnName ); }
	public function setColumnName( $columnName ){
		return( $this->_columnName = Torpor::containerKeyName( $columnName ) );
	}

	public function setNegated( $bool = true ){ return( $this->_not = ( $bool ? true : false ) ); }
	public function isNegated(){ return( $this->_not ); }

	public function isColumnTarget(){ return( $this->_columnTarget ); }
	public function setColumnTarget( $bool = true ){ return( $this->_columnTarget = ( $bool ? true : false ) ); }

	public function getBaseType(){ return( $this->_baseType ); }
	public function getType(){ return( $this->_type ); }
	public function setType( $type ){
		$type = strtoupper( $type );
		if( !in_array( $type, self::getValidTypes() ) ){
			throw( new TorporException( '"'.$type.'" is not a valid type' ) );
		}
		$baseType = $type;
		if( strpos( $baseType, self::NOT ) === 0 ){
			$this->setNegated();
			$baseType = substr( $baseType, strlen( self::NOT ) );
		} else {
			$this->setNegated( false );
		}
		if( $pos = strrpos( $baseType, self::COLUMN ) ){
			$this->setColumnTarget();
			$baseType = substr( $baseType, 0, $pos );
		} else {
			$this->setColumnTarget( false );
		}
		$this->_baseType = $baseType;
		return( $this->_type = $type ); // Set the original as well.
	}

	public function isInclusive(){ return( $this->_inclusive ); }
	public function setInclusive( $bool = true ){ return( $this->_inclusive = ( $bool ? true : false ) ); }

	public function isCaseSensitive(){ return( !$this->isCaseInsensitive() ); }
	public function setCaseSensitive( $bool = true ){ return( $this->setCaseInsensitive( !$bool ) ); }
	public function isCaseInsensitive(){ return( $this->_caseInsensitive ); }
	public function setCaseInsensitive( $bool = true ){ return( $this->_caseInsensitive = ( $bool ? true : false ) ); }

	public function getCustom(){ return( $this->getType() == self::TYPE_CUSTOM ? $this->_custom : null ); }
	public function setCustom( $custom ){
		$return = false;
		if( !empty( $custom ) ){
			$this->setType( self::TYPE_CUSTOM );
			$return = $this->_custom = $custom;
		}
		return( $return );
	}

	public function addArgument( $arg ){
		if( is_array( $arg ) ){
			return( $this->addColumnArgument( $arg ) );
		}
		return( $this->_args[] = $arg );
	}
	public function addColumnArgument( $arg ){
		return( $this->_columnArgs[] = $arg );
	}

	public function &getArguments(){
		$arguments = null;
		if( $this->isColumnTarget() ){
			$arguments = &$this->_columnArgs;
		} else {
			$arguments = &$this->_args;
		}
		return $arguments;
	}

	protected function processArgs( array $args ){
		$flatArgs = array();
		while( count( $args ) ){
			$arg = array_shift( $args );
			if( is_array( $arg ) ){
				$args = array_merge( $arg, $args );
			} else {
				$flatArgs[] = $arg;
			}
		}

		$preppedArgs = array();
		for( $i = 0; $i < count( $flatArgs ); $i++ ){
		 	if(
				$this->isColumnTarget()
				&& ( $i < ( count( $flatArgs ) - 1 ) )
			){
				$grid = null;
				$column = null;
				if( $flatArgs[$i] instanceof Column ){
					$grid = Torpor::containerKeyName( $flatArgs[$i]->Grid() );
					$column = Torpor::containerKeyName( $flatArgs[$i] );
				} else {
					$grid = Torpor::containerKeyName( $flatArgs[$i++] );
					if( !isset( $flatArgs[ $i ] ) ){
						throw( new TorporException( 'Grid/Column count mismatch in criteria type '.$this->getType() ) );
					}
					$column = Torpor::containerKeyName( $flatArgs[$i] );
				}
				$preppedArgs[] = array( $grid, $column );
		 	} else {
		 		$preppedArgs[] = $flatArgs[$i];
		 	}
		}

		$preppedArgCount = count( $preppedArgs );
		$argCountException = new TorporException( 'Invalid argument count for type '.$this->getType() );
		switch( $this->getBaseType() ){
			case self::TYPE_BETWEEN:
				if( $preppedArgCount < 2 || $preppedArgCount > 3 ){ throw( $argCountException ); }
				$preppedArgs[] = false; // Default for "inclusive"
				list( $rangeOne, $rangeTwo, $inclusive ) = $preppedArgs;
				$this->addArgument( $rangeOne );
				$this->addArgument( $rangeTwo );
				$this->setInclusive( $inclusive );
				break;
			case self::TYPE_STARTSWITH:
			case self::TYPE_CONTAINS:
			case self::TYPE_ENDSWITH:
			case self::TYPE_EQUALS:
				if( $preppedArgCount < 1 || $preppedArgCount > 2 ){ throw( $argCountException ); }
				$preppedArgs[] = false; // Default for "case insensitive"
				list( $targetValue, $caseInsensitive ) = $preppedArgs;
				$this->addArgument( $targetValue );
				$this->setCaseInsensitive( $caseInsensitive );
				break;
			case self::TYPE_GREATERTHAN:
			case self::TYPE_LESSTHAN:
				if( $preppedArgCount < 1 || $preppedArgCount > 2 ){ throw( $argCountException ); }
				$preppedArgs[] = false; // Default for "inclusive"
				list( $targetValue, $inclusive ) = $preppedArgs;
				$this->addArgument( $targetValue );
				$this->setInclusive( $inclusive );
				break;
			case self::TYPE_CUSTOM:
				$this->setCustom( array_shift( $preppedArgs ) );
			case self::TYPE_IN:
				if( $preppedArgCount < 1 ){ throw( $argCountException ); }
				foreach( $preppedArgs as $arg ){
					$this->addArgument( $arg );
				}
				break;
			case self::TYPE_IN_SET:
				if( $preppedArgCount != 1 ){ throw( $argCountException ); }
				$set = array_shift( $preppedArgs );
				if( !( $set instanceof GridSet ) ){
					throw( new TorporException( 'Argument for '.$this->getType().' criteria type must be an instance of GridSet' ) );
				}
				$this->addArgument( $set );
				break;
			case self::TYPE_PATTERN:
				if( $preppedArgCount != 1 ){ throw( $argCountException ); }
				$this->addArgument( array_shift( $preppedArgs ) );
				break;
			case null:
			default:
				throw( new TorporException( 'Type invalid or not set' ) );
				break;
		}
	}

	public function validate(){
		$return = true;
		if(
			!$this->getGridName()
			|| !$this->getColumnName()
			|| !$this->getType()
			|| !$this->getBaseType()
		){
			$return = false;
		}
		if( $return ){
			$argCount = count( $this->getArguments() );
			$argTarget = 0;
			switch( $this->getBaseType() ){
				case self::TYPE_BETWEEN:
					$argTarget = 2;
					break;
				case self::TYPE_CONTAINS:
				case self::TYPE_ENDSWITH:
				case self::TYPE_EQUALS:
				case self::TYPE_GREATERTHAN:
				case self::TYPE_LESSTHAN:
				case self::TYPE_PATTERN:
				case self::TYPE_STARTSWITH:
					$argTarget = 1;
					break;
				case self::TYPE_IN_SET:
					$argTarget = 1;
					$args = &$this->getArguments();
					if( !( $args[0] instanceof GridSet ) ){
						throw( new TorporException( 'Argument for '.$this->getType().' criteria type must be an instance of GridSet' ) );
					}
					break;
				case self::TYPE_CUSTOM:
					$argTarget = 0;
					if( strlen( trim( $this->getCustom() ) ) == 0 ){
						$return = false;
					}
					break;
				case self::TYPE_IN:
					$argTarget = -1;
					break;
			}
			if( $argCount < abs( $argTarget ) ){
				$return = false;
			}
			if( $argTarget > 0 && $argCount > $argTarget ){
				trigger_error( 'Too many arguments for '.$this->getType().' criteria type, only the first '.$argTarget.' will be used', E_USER_WARNING );
			}
		}
		return( $return );
	}

	public static function getValidBaseTypes(){
		return( 
			array(
				self::TYPE_BETWEEN,
				self::TYPE_CONTAINS,
				self::TYPE_CUSTOM,
				self::TYPE_ENDSWITH,
				self::TYPE_EQUALS,
				self::TYPE_GREATERTHAN,
				self::TYPE_IN,
				self::TYPE_IN_SET,
				self::TYPE_LESSTHAN,
				self::TYPE_PATTERN,
				self::TYPE_STARTSWITH
			)
		);
	}

	public static function getValidTypes(){
		return(
			array(
				self::TYPE_BETWEEN,
				self::TYPE_BETWEEN_COLUMN,
				self::TYPE_NOT_BETWEEN,
				self::TYPE_NOT_BETWEEN_COLUMN,
				self::TYPE_CONTAINS,
				self::TYPE_CONTAINS_COLUMN,
				self::TYPE_NOT_CONTAINS,
				self::TYPE_NOT_CONTAINS_COLUMN,
				self::TYPE_CUSTOM,
				self::TYPE_CUSTOM_COLUMN,
				self::TYPE_NOT_CUSTOM,
				self::TYPE_NOT_CUSTOM_COLUMN,
				self::TYPE_ENDSWITH,
				self::TYPE_ENDSWITH_COLUMN,
				self::TYPE_NOT_ENDSWITH,
				self::TYPE_NOT_ENDSWITH_COLUMN,
				self::TYPE_EQUALS,
				self::TYPE_EQUALS_COLUMN,
				self::TYPE_NOT_EQUALS,
				self::TYPE_NOT_EQUALS_COLUMN,
				self::TYPE_GREATERTHAN,
				self::TYPE_GREATERTHAN_COLUMN,
				self::TYPE_NOT_GREATERTHAN,
				self::TYPE_NOT_GREATERTHAN_COLUMN,
				self::TYPE_IN,
				self::TYPE_IN_COLUMN,
				self::TYPE_NOT_IN,
				self::TYPE_NOT_IN_COLUMN,
				self::TYPE_IN_SET,
				self::TYPE_NOT_IN_SET,
				self::TYPE_LESSTHAN,
				self::TYPE_LESSTHAN_COLUMN,
				self::TYPE_NOT_LESSTHAN,
				self::TYPE_NOT_LESSTHAN_COLUMN,
				self::TYPE_PATTERN,
				self::TYPE_STARTSWITH,
				self::TYPE_STARTSWITH_COLUMN,
				self::TYPE_NOT_STARTSWITH,
				self::TYPE_NOT_STARTSWITH_COLUMN
			)
		);
	}
}

class CriteriaBetween extends Criteria {};
class CriteriaBetweenColumn extends Criteria {};
class CriteriaNotBetween extends Criteria {};
class CriteriaNotBetweenColumn extends Criteria {};
class CriteriaContains extends Criteria {};
class CriteriaContainsColumn extends Criteria {};
class CriteriaNotContains extends Criteria {};
class CriteriaNotContainsColumn extends Criteria {};
class CriteriaCustom extends Criteria {};
class CriteriaCustomColumn extends Criteria {};
class CriteriaNotCustom extends Criteria {};
class CriteriaNotCustomColumn extends Criteria {};
class CriteriaEndsWith extends Criteria {};
class CriteriaEndsWithColumn extends Criteria {};
class CriteriaNotEndsWith extends Criteria {};
class CriteriaNotEndsWithColumn extends Criteria {};
class CriteriaEquals extends Criteria {};
class CriteriaEqualsColumn extends Criteria {};
class CriteriaNotEquals extends Criteria {};
class CriteriaNotEqualsColumn extends Criteria {};
class CriteriaGreaterThan extends Criteria {};
class CriteriaGreaterThanColumn extends Criteria {};
class CriteriaNotGreaterThan extends Criteria {};
class CriteriaNotGreaterThanColumn extends Criteria {};
class CriteriaIn extends Criteria {};
class CriteriaInColumn extends Criteria {};
class CriteriaNotIn extends Criteria {};
class CriteriaNotInColumn extends Criteria {};
class CriteriaInSet extends Criteria {};
class CriteriaNotInSet extends Criteria {};
class CriteriaLessThan extends Criteria {};
class CriteriaLessThanColumn extends Criteria {};
class CriteriaNotLessThan extends Criteria {};
class CriteriaNotLessThanColumn extends Criteria {};
class CriteriaPattern extends Criteria {};
class CriteriaStartsWith extends Criteria {};
class CriteriaStartsWithColumn extends Criteria {};
class CriteriaNotStartsWith extends Criteria {};
class CriteriaNotStartsWithColumn extends Criteria {};

?>
