<?php  if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * Code Igniter
 *
 * An open source application development framework for PHP 4.3.2 or newer
 *
 * @package		CodeIgniter
 * @author		Rick Ellis
 * @copyright	Copyright (c) 2006, pMachine, Inc.
 * @license		http://www.codeignitor.com/user_guide/license.html 
 * @link		http://www.codeigniter.com
 * @since		Version 1.0
 * @filesource
 */
 
// ------------------------------------------------------------------------

/**
 * Database Driver Class
 * 
 * This is the platform-independent base DB implementation class.
 * This class will not be called directly. Rather, the adapter
 * class for the specific database will extend and instantiate it.
 *
 * @package		CodeIgniter
 * @subpackage	Drivers
 * @category	Database
 * @author		Rick Ellis
 * @link		http://www.codeigniter.com/user_guide/database/
 */
class CI_DB_driver {

	var $username;
	var $password;
	var $hostname;
	var $database;
	var $dbdriver		= 'mysql';
	var $dbprefix		= '';
	var $port			= '';
	var $pconnect		= FALSE;
	var $conn_id		= FALSE;
	var $result_id		= FALSE;
	var $db_debug		= FALSE;
	var $benchmark		= 0;
	var $query_count	= 0;
	var $bind_marker	= '?';
	var $queries		= array();
	var $trans_enabled	= TRUE;
	var $_trans_depth	= 0;
	var $_trans_failure	= FALSE; // Used with transactions to determine if a rollback should occur

    // These are use with Oracle
    var $stmt_id;
    var $curs_id;
    var $limit_used;

	
	/**
	 * Constructor.  Accepts one parameter containing the database
	 * connection settings. 
	 *
	 * Database settings can be passed as discreet 
	 * parameters or as a data source name in the first 
	 * parameter. DSNs must have this prototype:
	 * $dsn = 'driver://username:password@hostname/database';
	 *
	 * @param mixed. Can be an array or a DSN string
	 */	
	function CI_DB_driver($params)
	{
		$this->initialize($params);
		log_message('debug', 'Database Driver Class Initialized');
	}
	
	// --------------------------------------------------------------------

	/**
	 * Initialize Database Settings
	 *
	 * @access	private Called by the constructor
	 * @param	mixed
	 * @return	void
	 */	
	function initialize($params = '')
	{	
		if (is_array($params))
		{
			foreach (array('hostname' => '', 'username' => '', 'password' => '', 'database' => '', 'dbdriver' => 'mysql', 'dbprefix' => '', 'port' => '', 'pconnect' => FALSE, 'db_debug' => FALSE) as $key => $val)
			{
				$this->$key = ( ! isset($params[$key])) ? $val : $params[$key];
			}
		}
		elseif (strpos($params, '://'))
		{
			if (FALSE === ($dsn = @parse_url($params)))
			{
				log_message('error', 'Invalid DB Connection String');
			
				if ($this->db_debug)
				{
					return $this->display_error('db_invalid_connection_str');
				}
				return FALSE;			
			}
			
			$this->hostname = ( ! isset($dsn['host'])) ? '' : rawurldecode($dsn['host']);
			$this->username = ( ! isset($dsn['user'])) ? '' : rawurldecode($dsn['user']);
			$this->password = ( ! isset($dsn['pass'])) ? '' : rawurldecode($dsn['pass']);
			$this->database = ( ! isset($dsn['path'])) ? '' : rawurldecode(substr($dsn['path'], 1));
		}
	
		if ($this->pconnect == FALSE)
		{
			$this->conn_id = $this->db_connect();
		}
		else
		{
			$this->conn_id = $this->db_pconnect();
		}	
       
        if ( ! $this->conn_id)
        { 
			log_message('error', 'Unable to connect to the database');
			
            if ($this->db_debug)
            {
				$this->display_error('db_unable_to_connect');
            }
        }
		else
		{
			if ( ! $this->db_select())
			{
				log_message('error', 'Unable to select database: '.$this->database);
			
				if ($this->db_debug)
				{
					$this->display_error('db_unable_to_select', $this->database);
				}
			}	
		}	
	}
	
	
	// --------------------------------------------------------------------

	/**
	 * Execute the query
	 *
	 * Accepts an SQL string as input and returns a result object upon 
	 * successful execution of a "read" type query.  Returns boolean TRUE 
	 * upon successful execution of a "write" type query. Returns boolean 
	 * FALSE upon failure, and if the $db_debug variable is set to TRUE 
	 * will raise an error.
	 * 
	 * @access	public
	 * @param	string	An SQL query string
	 * @param	array	An array of binding data
	 * @return	mixed		 
	 */	
    function query($sql, $binds = FALSE, $return_object = TRUE)
    {    
		if ($sql == '')
		{
            if ($this->db_debug)
            {
				log_message('error', 'Invalid query: '.$sql);
				return $this->display_error('db_invalid_query');
            }
            return FALSE;        
		}
		
		// Compile binds if needed
		if ($binds !== FALSE)
		{
			$sql = $this->compile_binds($sql, $binds);
		}

        // Save the  query for debugging
        $this->queries[] = $sql;

		// Start the Query Timer
        $time_start = list($sm, $ss) = explode(' ', microtime());
      
		// Run the Query
        if (FALSE === ($this->result_id = $this->simple_query($sql)))
        { 
        	// This will trigger a rollback if transactions are being used
        	$this->_trans_failure = TRUE;
        	
            if ($this->db_debug)
            {
				log_message('error', 'Query error: '.$this->error_message());
				return $this->display_error(
										array(
												'Error Number: '.$this->error_number(), 
												$this->error_message(),
												$sql
											)
										);
            }
          
          return FALSE;
        }
        
		// Stop and aggregate the query time results
		$time_end = list($em, $es) = explode(' ', microtime());
		$this->benchmark += ($em + $es) - ($sm + $ss);

		// Increment the query counter
        $this->query_count++;
        
		// Was the query a "write" type?
		// If so we'll simply return true
		if ($this->is_write_type($sql) === TRUE)
		{
			return TRUE;
		}
		
		// Return TRUE if we don't need to create a result object 
		// Currently only the Oracle driver uses this when stored
		// procedures are used
		if ($return_object !== TRUE)
		{
			return TRUE;
		}
		
		// Define the result driver name
		$result = 'CI_DB_'.$this->dbdriver.'_result';
		
		// Load the result classes
		if ( ! class_exists($result))
		{
			include_once(BASEPATH.'database/DB_result'.EXT);
			include_once(BASEPATH.'database/drivers/'.$this->dbdriver.'/'.$this->dbdriver.'_result'.EXT);		
		}

		// Instantiate the result object	
        $RES = new $result();
        $RES->conn_id	= $this->conn_id;
        $RES->db_debug	= $this->db_debug;
        $RES->result_id	= $this->result_id;
        
        if ($this->dbdriver == 'oci8')
        {
			$RES->stmt_id   = $this->stmt_id;
			$RES->curs_id   = NULL;
			$RES->limit_used = $this->limit_used;
        }

		return $RES;
	}
	
	// --------------------------------------------------------------------

	/**
	 * Simple Query  
	 * This is a simiplified version of the query() function.  Internally
	 * we only use it when running transaction commands since they do
	 * not require all the features of the main query() function.
	 * 
	 * @access	public
	 * @param	string	the sql query
	 * @return	mixed		 
	 */	
	function simple_query($sql)
	{
		if ( ! $this->conn_id)
		{
			$this->initialize();
		}

		return $this->_execute($sql, $this->conn_id);
	}
	
	// --------------------------------------------------------------------

	/**
	 * Disable Transactions
	 * This permits transactions to be disabled at run-time.
	 * 
	 * @access	public
	 * @return	void		 
	 */	
	function trans_off()
	{
		$this->trans_enabled = FALSE;
	}

	// --------------------------------------------------------------------

	/**
	 * Start Transaction
	 * 
	 * @access	public
	 * @return	void		 
	 */	
	function trans_start($test_mode = FALSE)
	{	
		if ( ! $this->trans_enabled)
		{
			return FALSE;
		}

		// When transactions are nested we only begin/commit/rollback the outermost ones
		if ($this->_trans_depth > 0)
		{
			$this->_trans_depth += 1;
			return;
		}
		
		$this->trans_begin($test_mode);
	}

	// --------------------------------------------------------------------

	/**
	 * Complete Transaction
	 * 
	 * @access	public
	 * @return	bool		 
	 */	
	function trans_complete()
	{
		if ( ! $this->trans_enabled)
		{
			return FALSE;
		}
	
		// When transactions are nested we only begin/commit/rollback the outermost ones
		if ($this->_trans_depth > 1)
		{
			$this->_trans_depth -= 1;
			return TRUE;
		}
	
		// The query() function will set this flag to TRUE in the event that a query failed
		if ($this->_trans_failure === TRUE)
		{
			$this->trans_rollback();
			
			if ($this->db_debug)
			{
				return $this->display_error('db_transaction_failure');
			}
			return FALSE;			
		}
		
		$this->trans_commit();
		return TRUE;	
	}

	// --------------------------------------------------------------------

	/**
	 * Lets you retrieve the transaction flag to determine if it has failed
	 * 
	 * @access	public
	 * @return	bool		 
	 */	
	function trans_status()
	{
		return $this->_trans_failure;
	}

	// --------------------------------------------------------------------

	/**
	 * Compile Bindings
	 * 
	 * @access	public
	 * @param	string	the sql statement
	 * @param	array	an array of bind data
	 * @return	string		 
	 */	
	function compile_binds($sql, $binds)
	{	
		if (FALSE === strpos($sql, $this->bind_marker))
		{
			return $sql;
		}
		
		if ( ! is_array($binds))
		{
			$binds = array($binds);
		}
		
		foreach ($binds as $val)
		{
			$val = $this->escape($val);
					
			// Just in case the replacement string contains the bind
			// character we'll temporarily replace it with a marker
			$val = str_replace($this->bind_marker, '{%bind_marker%}', $val);
			$sql = preg_replace("#".preg_quote($this->bind_marker, '#')."#", str_replace('$', '\$', $val), $sql, 1);
		}

		return str_replace('{%bind_marker%}', $this->bind_marker, $sql);		
	}
	
	// --------------------------------------------------------------------

	/**
	 * Determines if a query is a "write" type. 
	 * 
	 * @access	public
	 * @param	string	An SQL query string
	 * @return	boolean		 
	 */	
	function is_write_type($sql)
	{
		if ( ! preg_match('/^\s*"?(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|LOAD DATA|COPY|ALTER|GRANT|REVOKE|LOCK|UNLOCK)\s+/i', $sql)) 
		{
			return FALSE;
		}
		return TRUE;
	}
	
	// --------------------------------------------------------------------

	/**
	 * Calculate the aggregate query elapsed time 
	 * 
	 * @access	public
	 * @param	intiger	The number of decimal places
	 * @return	integer		 
	 */	
	function elapsed_time($decimals = 6)
	{
		return number_format($this->benchmark, $decimals);
	}
	
	// --------------------------------------------------------------------

	/**
	 * Returns the total number of queries
	 * 
	 * @access	public
	 * @return	integer		 
	 */	
	function total_queries()
	{
		return $this->query_count;
	}
	
	// --------------------------------------------------------------------

	/**
	 * Returns the last query that was executed
	 * 
	 * @access	public
	 * @return	void		 
	 */	
	function last_query()
	{
		return end($this->queries);
	}
	
	// --------------------------------------------------------------------

	/**
	 * "Smart" Escape String
	 *
	 * Escapes data based on type
	 * Sets boolean and null types
	 * 
	 * @access	public
	 * @param	string
	 * @return	integer		 
	 */	
	function escape($str)
	{	
		switch (gettype($str))
		{
			case 'string'	:	$str = "'".$this->escape_str($str)."'";
				break;
			case 'boolean'	:	$str = ($str === FALSE) ? 0 : 1;
				break;
			default			:	$str = ($str === NULL) ? 'NULL' : $str;
				break;
		}		

		return $str;
	}	

	// --------------------------------------------------------------------

	/**
	 * Enables a native PHP function to be run, using a platform agnostic wrapper.
	 * 
	 * @access	public
	 * @param	string	the function name
	 * @param	mixed	any parameters needed by the function
	 * @return	mixed		 
	 */	
	function call_function($function)
	{
		$driver = ($this->dbdriver == 'postgre') ? 'pg_' : $this->dbdriver.'_';
	
		if (FALSE === strpos($driver, $function))
		{
			$function = $driver.$function;
		}
		
		if ( ! function_exists($function))
		{ 
			if ($this->db_debug)
			{
				return $this->display_error('db_unsupported_function');
			}
			return FALSE;			
		}
		else
		{
			$args = (func_num_args() > 1) ? array_shift(func_get_args()) : null;
			
			return call_user_func_array($function, $args); 
		}
	}
	
	// --------------------------------------------------------------------

	/**
	 * Close DB Connection
	 * 
	 * @access	public
	 * @return	void		 
	 */	
    function close()
    {
        if (is_resource($this->conn_id))
        {
            $this->_close($this->conn_id);
		}   
		$this->conn_id = FALSE;
    }
	
	// --------------------------------------------------------------------

	/**
	 * Display an error message
	 * 
	 * @access	public
	 * @param	string	the error message
	 * @param	string	any "swap" values
	 * @param	boolean	whether to localize the message
	 * @return	string	sends the application/errror_db.php template		 
	 */	
    function display_error($error = '', $swap = '', $native = FALSE) 
    {
		$LANG = new CI_Language();
		$LANG->load('db');

		$heading = 'MySQL Error';
		
		if ($native == TRUE)
		{
			$message = $error;
		}
		else
		{
			$message = ( ! is_array($error)) ? array(str_replace('%s', $swap, $LANG->line($error))) : $error;
		}

		if ( ! class_exists('CI_Exceptions'))
		{
			include_once(BASEPATH.'libraries/Exceptions'.EXT);
		}
		
		$error = new CI_Exceptions();
		echo $error->show_error('An Error Was Encountered', $message, 'error_db');
		exit;

    }  
	
}

?>