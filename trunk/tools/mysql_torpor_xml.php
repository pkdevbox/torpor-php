#!/usr/bin/php
<?PHP
// TODO: Will need to identify PHP path accurately
$args = array(
	'host' => 'localhost',
	'user' => '',
	'password' => ''
);
for( $i = 1; $i < count( $argv ); $i++ ){
	if( preg_match( '/^--\w+/', $argv[$i] ) ){
		$args{ preg_replace( '/^--/', '', $argv[$i] ) } = $argv[++$i];
	} else {
		$args{ $argv[$i] } = true;
	}
}
if( !$args['db'] ){
	die( "Need a target database to continue\n" );
}

$connection = mysql_connect( $args['host'], $args['user'], $args['password'] ) or die( mysql_error() );
mysql_select_db( 'information_schema', $connection ) or die( mysql_error() );

$dataTypeMap = array(
	'char' => 'char',
	'varchar' => 'varchar',
	'tinyint' => 'integer',
	'smallint' => 'integer',
	'mediumint' => 'integer',
	'bigint' => 'integer',
	'longtext' => 'text',
	'decimal' => 'float',
	'date' => 'date'
	'datetime' => 'datetime',
	'int' => 'integer',
	'timestamp' => 'datetime',
	'text' => 'text',
	'enum' => 'varchar', // TODO!!
	'double' => 'double',
	'tinytext' => 'varchar',
	'mediumint' => 'int',
	'float unsigned' => 'float',
	'mediumtext' => 'text',
	'set' => 'varchar', // TODO!!
	'time' => 'time',
	'longblog' => 'binary',
	'blog' => 'binary'
);

$grids = array();
$table_result = mysql_query( 'SELECT TABLE_NAME FROM TABLES WHERE TABLE_SCHEMA = "'.mysql_real_escape_string( $args['db'] ).'"' ) or die( mysql_error() );
print "<trpr:TorporConfig version=\"0.1\" xmlns:trpr=\"http://www.tricornersoftware.com/Products/Torpor/Config/0.1\">\n";
print "\t<Grids>\n";
while( $table_obj = mysql_fetch_object( $table_result ) ){
	$column_result = mysql_query( 'SELECT * FROM COLUMNS WHERE TABLE_SCHEMA = "'.mysql_real_escape_string( $args['db'] ).'" AND TABLE_NAME = "'.$table_obj->TABLE_NAME.'" ORDER BY ORDINAL_POSITION' ) or die( mysql_error() );
	if( mysql_num_rows( $column_result ) > 0 ){
		print "\t\t<Grid dataName=\"".$table_obj->TABLE_NAME."\">\n";
		print "\t\t\t<Columns>\n";
		while( $column_obj = mysql_fetch_object( $column_result ) ){
			print "\t\t\t\t<Column "
				.' dataName="'.$column_obj->COLUMN_NAME.'"'
				.' type="'.$dataTypeMap[ $column_obj->DATA_TYPE ].'"'
				.( $column_obj->IS_NULLABLE == 'YES' ? ' nullable="true"' : '' )
				.( $column_obj->CHARACTER_MAXIMUM_LENGTH ? ' length="'.$column_obj->CHARACTER_MAXIMUM_LENGTH.'"' : '' )
				.( $column_obj->CHARACTER_SET_NAME ? ' encoding="'.$column_obj->CHARACTER_SET_NAME.'"' : '' )
				.( $column_obj->NUMERIC_PRECISION ? ' precision="'.$column_obj->NUMERIC_PRECISION.'"' : '' )
				.( $column_obj->COLUMN_DEFAULT && $column_obj->COLUMN_DEFAULT != 'CURRENT_TIMESTAMP' ? ' default="'.htmlentities( $column_obj->COLUMN_DEFAULT ).'"' : '' )
				.( $column_obj->EXTRA == 'auto_increment' || $column_obj->COLUMN_DEFAULT == 'CURRENT_TIMESTAMP' ? ' generatedOnPublish="true"' : '' )
				."/>\n";
		}
		print "\t\t\t</Columns>\n";
		print "\t\t\t<Keys>\n";
		$pk_result = mysql_query( 'SELECT * FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = "'.mysql_real_escape_string( $args['db'] ).'" AND TABLE_NAME = "'.$table_obj->TABLE_NAME.'" AND CONSTRAINT_NAME = "PRIMARY" ORDER BY ORDINAL_POSITION' ) or die( mysql_error() );
		if( mysql_num_rows( $pk_result ) > 0 ){
			print "\t\t\t\t<Primary>\n";
			while( $key_obj = mysql_fetch_object( $pk_result ) ){
				print "\t\t\t\t\t<Key column=\"".$key_obj->COLUMN_NAME."\"/>\n";
			}
			print "\t\t\t\t</Primary>\n";
		}
		$unique_result = mysql_query( 'SELECT * FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = "'.mysql_real_escape_string( $args['db'] ).'" AND TABLE_NAME = "'.$table_obj->TABLE_NAME.'" AND CONSTRAINT_NAME <> "PRIMARY" ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION' ) or die( mysql_error() );
		if( mysql_num_rows( $unique_result ) > 0 ){
			print "\t\t\t\t<Unique>\n";
			$first = true;
			$last_constraint_name = '';
			while( $key_obj = mysql_fetch_object( $unique_result ) ){
				if( $last_constraint_name != $key_obj->CONSTRAINT_NAME && !$first ){
					print "\t\t\t\t</Unique>\n\t\t\t\t<Unique>\n";
				}
				print "\t\t\t\t\t<Key column=\"".$key_obj->COLUMN_NAME."\"/>\n";
				$last_constraint_name = $key_obj->CONSTRAINT_NAME;
				$first = false;
			}
			print "\t\t\t\t</Unique>\n";
		}
		print "\t\t\t</Keys>\n";
		print "\t\t</Grid>\n";
	}
	// break;
}
print "\t</Grids>\n";
print "</trpr:TorporConfig>\n";

?>
