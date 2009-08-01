<?PHP
// TODO: Make sure that everything passes this check.  This is the equivalent
// of 'use strict' in PHP - although it can only produce warnings, they're still
// useful in directing attention to probable typos etc.
error_reporting (E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// TODO: Lazy loading.
include_once( '../Torpor.php' );

// TODO: Need a way to instantiate this object, not as ColumnAsClass, but
// as an actual column instead.  This should be used in the custom column type
// in the XML.
class PasswordHash extends Column
{
	public function setData( $data ){
		if( $data == $this->User()->getUserName() ){
			// TODO: TorporError class which identifies the column & grid responsible?
			$this->Torpor()->addErrorString( 'Password cannot be identical to username' );
			return( false );
		}
		// TODO: should have a different, non-validating means of setting
		// the internal data that keeps all the hasData() calls correctly
		// in line?
		parent::setData( md5( $data ) );
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

// TODO: Global column type override options: <ColumnOverride type="int" class="MyIntClass"/>
// Local overrides (w/keyword none): <Column name="Id" dataName="USER_ID" type="int" class="none"/>
// TODO: Column type of Class (class must extend Column) w/class attribute to say which kind.
// TODO: Grid type class (class must extend Grid) to instantiate specific grids as a target
// class of the configuration's choosing (perhaps have local commands to do similar things, which
// can easily be leveraged by that expansion).
// TODO: Global default for columns of type 'X', e.g., setting 'ApplicationName' to be a user-defined
// value that's stuck into the specified columns, and which can be set via the options interface
// on the Torpor main class.
// TODO: Default values for columns (and perhaps have the default value maintain an addressing
// scheme such as 'userDefined:ApplicationName' which indicates where to get that default value.
// TODO: Data fetch specifications making use of stored procedures/functions instead of tables.
// TODO: Be able to mark grids as read-only
$xmlConfig = <<<XML
<?xml version='1.0'?>
<trpr:TorporConfig version="0.1" xmlns:trpr="http://www.tricornersoftware.com/Products/Torpor/Config/0.1">
	<Database/>
	<Options/>
	<Grids>
		<Grid name="User" dataName="USERS" class="User">
			<Columns>
				<Column name="Id" dataName="USER_ID" type="unsigned"/>
				<Column name="UserName" dataName="USER_NAME" type="varchar" length="255"/>
				<Column name="Email" dataName="EMAIL_ADDRESS" type="varchar" length="255"/>
				<Column name="PasswordHash" dataName="PASSWORD" type="class" class="PasswordHash" length="32"/>
			</Columns>
			<Keys>
				<Primary>
					<Key column="Id"/>
				</Primary>
				<Unique>
					<Key column="UserName"/>
				</Unique>
				<Unique>
					<Key column="Email"/>
				</Unique>
				<Foreign/>
			</Keys>
		</Grid>
		<Grid name="Order" dataName="ORDERS">
			<Columns>
				<Column name="Id" dataName="ORDER_ID" type="unsigned"/>
				<Column name="UserId" dataName="USER_ID" type="unsigned"/>
				<Column name="Date" dataName="ORDER_DATE" type="datetime"/>
				<Column name="ShippingAddressId" dataName="SHIPPING_ADDRESS" type="unsigned"/>
				<Column name="ShippingTypeId" dataName="SHIPPING_TYPE" type="unsigned"/>
			</Columns>
			<Keys>
				<Primary>
					<Key column="Id"/>
				</Primary>
				<Unique>
					<Key column="UserId"/>
					<Key column="Date"/>
				</Unique>
				<Foreign>
					<Key column="UserId" referenceGrid="User" referenceColumn="Id"/>
				</Foreign>
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
if( !$user->setPasswordHash( 'george' ) ){
	var_dump( $user->Torpor()->nextError() );
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
foreach( $userToo->columnNames() as $column ){
	var_dump( $userToo->$column );
}
foreach( $userToo as $columnName => $column ){
	var_dump( $columnName.' = '.var_export( $column->getData(), true ) );
}

// One-to-many fetch
$orderSet = $userToo->getOrderSet();

$orderSet = new GridSet();
$orderSet->addOrder( Torpor()->newOrder() );
$orderSet->addOrder( Torpor()->newOrder() );
$orderSet->addOrder( Torpor()->newOrder() );

$orderSet->getFirstOrder()->ID = 1;
$orderSet->getNextOrder()->setId( 2 );
$orderSet->getNextOrder()->setId( 5 );

foreach( $orderSet as $order ){
	var_dump( $order->ID );
}

$order = Torpor()->newOrder();
// One-to-one fetch
$userThree = $order->getUser();

var_dump( get_class( Torpor()->newUser() ) );

?>
