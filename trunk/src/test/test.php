<?PHP
// TODO: Make sure that everything passes this check.  This is the equivalent
// of 'use strict' in PHP - although it can only produce warnings, they're still
// useful in directing attention to probable typos etc.
error_reporting ( E_ALL | E_STRICT );

// TODO: Lazy loading.
include_once( '../Torpor.php' );

// TODO: Need a way to instantiate this object, not as ColumnAsClass, but
// as an actual column instead.  This should be used in the custom column type
// in the XML.
class PasswordHash extends Column
{
	public function validate( $data ){
		if( $data == $this->User()->getUserName() ){
			// TODO: TorporError class which identifies the column & grid responsible?
			$this->throwException( 'Password cannot be identical to username' );
		}
		return( md5( $data ) );
	}
}

class User extends Grid
{
	// Named class type, can be returned from Torpor based on configuration for grids
	// of the requested type, which will allow users a degree of confidence in their
	// extended code and type hinting (can use ($user instanceof User) rather than
	// ($user instanceof Grid) as the generic).  In fact, if a class of the grid name
	// does not already exist, it might make sense to go ahead and create it internally.
	// Also: marvelous for expansion and business logic.
}

class DoNothingDataStore implements DataStore {
	public static function createInstance( Torpor $torpor ){ return( new DoNothingDataStore() ); }
	public function initialize( $writeEnabled, array $settings ){}
	public function Delete( Grid $grid ){}
	public function Execute( PersistenceCommand $command, $returnAs = null /*, $grid1, ... */ ){}
	public function Load( Grid $grid, $refresh = false ){}
	public function LoadFromCriteria( Grid $grid, CriteriaBase $criteria, $refresh = false ){}
	public function LoadSet( GridSet $gridSet, $refresh = false ){}
	public function Publish( Grid $grid, $force = false ){}
}


// TODO: Be able to mark grids as read-only
$xmlConfig = <<<XML
<?xml version='1.0'?>
<trpr:TorporConfig version="0.1" xmlns:trpr="http://www.tricornersoftware.com/Products/Torpor/Config/0.1">
	<Repository>
		<DataStore type="DoNothing" class="DoNothingDataStore"/>
	</Repository>
	<Grids>
		<Grid name="User" dataName="USERS" class="User">
			<Columns>
				<Column name="Id" dataName="USER_ID" type="unsigned"/>
				<Column name="ReferringUserId" dataName="REFERRING_USER_ID" type="unsigned"/>
				<Column name="UserName" dataName="USER_NAME" type="varchar" length="255"/>
				<Column name="Email" dataName="EMAIL_ADDRESS" type="varchar" length="255"/>
				<Column name="PasswordHash" dataName="PASSWORD" type="varchar" class="PasswordHash" length="32"/>
			</Columns>
			<Keys>
				<Foreign>
					<Key column="ReferringUserId" referenceGrid="User" referenceGridAlias="ReferringUser" referenceColumn="Id"/>
				</Foreign>
				<Primary>
					<Key column="Id"/>
				</Primary>
				<Unique>
					<Key column="UserName"/>
				</Unique>
				<Unique>
					<Key column="Email"/>
				</Unique>
			</Keys>
		</Grid>
		<Grid name="Order" dataName="ORDERS">
			<Columns>
				<Column name="Id" dataName="ORDER_ID" type="unsigned"/>
				<Column name="UserId" dataName="USER_ID" type="unsigned"/>
				<Column name="SellerId" dataName="SELLER_ID" type="unsigned"/>
				<Column name="Date" dataName="ORDER_DATE" type="datetime"/>
				<Column name="ShippingAddressId" dataName="SHIPPING_ADDRESS" type="unsigned"/>
				<Column name="ShippingTypeId" dataName="SHIPPING_TYPE" type="unsigned"/>
			</Columns>
			<Keys>
				<Foreign>
					<Key column="UserId" referenceGrid="User" referenceColumn="Id"/>
					<Key column="SellerId" referenceGrid="User" referenceGridAlias="Seller" referenceColumn="Id"/>
				</Foreign>
				<Primary>
					<Key column="Id"/>
				</Primary>
				<Unique>
					<Key column="UserId"/>
					<Key column="Date"/>
				</Unique>
			</Keys>
		</Grid>
	</Grids>
</trpr:TorporConfig>
XML;

// A number of different ways of accessing the Torpor singleton instance exist.
// Either through a number of statically declared methods, an instance of the
// class, or through the instance returned by the global Torpor() function (a
// function of the same name as the class which simply returns
// Torpor::getInstance() - this last one is useful in versions of PHP 5.3.0
// which do not support the __callStatic method that would allow us to call the
// magical function names without referencing an instance of any kind)
var_dump( Torpor::isInitialized() );
var_dump( Torpor()->initialize( $xmlConfig ) );
var_dump( Torpor::isInitialized() );

// Create a grid instance in singleton context
$user = Torpor::getInstance()->newUser();
print_r( $user->columnNames() );
$user->setId( 12345 );
$user->setUserName( 'george' );
try {
	$user->setPasswordHash( 'george' );
} catch( TorporException $e ){
	print_r( 'Encountered exception: '.$e->getMessage()."\n" );
}
$user->setPasswordHash( 'something other than george' );
var_dump( $user->getId() );
var_dump( $user->getUserName() );
var_dump( $user->getEmail() );
var_dump( $user->getPasswordHash() );
print( "\n" );

// TODO: test juggling multiple instances or Torpor.
$torpor = Torpor::getInstance();
// Create a grid instance in object context
$userToo = $torpor->newUser();
// Access member methods as a simple object,
// atomically redirected to getX and setX
$userToo->ID = 54321;
$userToo->ID++; // Redirect works for increment operators too.
$userToo->UserName = 'harry';
$userToo->ReferringUserId = null;
foreach( $userToo->columnNames() as $column ){
	var_dump( $userToo->$column );
}
foreach( $userToo as $columnName => $column ){
	var_dump( $columnName.' = '.var_export( $column->getData(), true ) );
}

print_r( $userToo->dumpArray() );
print( "\n" );

// Dump only populated values - even if they've
// been populated with null.
print_r( $userToo->dumpObject( false ) );
print( "\n" );

$userX = clone( $userToo );
$userX->Id++;
$userX->setUserName( 'montreal' );
var_dump( $userX->Id()->Grid()->UserName );
var_dump( $userToo->UserName );

$referringUser = $userToo->getReferringUser();

// One-to-many fetch
$orderSet = $userToo->getOrderSet();

$orderSet = Torpor()->newOrderSet();
$orderSet->addOrder( Torpor()->newOrder() );
$orderSet->addOrder( Torpor()->newOrder() );
$orderSet->addOrder( Torpor()->newOrder() );

$orderSet->setUser( $user );
$orderSet->mapSeller( $userToo );

$orderSet->getFirstOrder()->ID = 1;
$orderSet->getNextOrder()->setId( 2 );
$orderSet->getNextOrder()->setId( 5 );

foreach( $orderSet as $order ){
	var_dump( $order->UserID );
	var_dump( $order->SellerID );
}

$order = Torpor()->newOrder();

// One-to-one fetch
$userThree = $order->getUser();

var_dump( get_class( Torpor()->newUser() ) );

var_dump( Torpor()->User()->UserName );

var_dump( Torpor()->primaryKeyForGrid( Torpor()->User ) );
var_dump( get_class( Torpor()->getUserById( 98765 ) ) );


?>
