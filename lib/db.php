<?php

/* PDO Database wrapper

	See PDO docs for details: http://www.php.net/manual/en/class.pdo.php

	Adds a few extensions for common batched operations.

	See related `extra/tagged-sql.php` for extra post-processing magic.
*/

class db extends PDO {

	private $statement;
	const USR_DEF_DB_ERR 	= '45000';
	const DB_NO_ERR 		= '00000';
	

	/* Create (or reuse) an instance of a PDO database */
	static function _instance($dsn, $user, $password, $config = null) {
		global $_db;
		if ($_db !== null) return $_db; // return cached

		if ($config === null)
			 $config = array( PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8' );

		try {
			 $_db = new db($dsn, $user, $password, $config);
		 } catch (Exception $e) {
			throw new Exception("Failed to connect to database.", 500, $e);
		 }

		return $_db;
	}

	/* Returns an array of classes based on the given SQL and bound parameters (see PDO docs for details) */
	function select($sql, $bound_parameters = array()) {
	
		// Expand any array parameters
		$this->expand_select_params($sql, $bound_parameters);

		$this->statement = $this->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		$this->statement->execute($bound_parameters);
		$resultset = $this->statement->fetchAll(PDO::FETCH_CLASS);
		$this->errors();
		return $resultset;
	}
	function select_row($sql, $bound_parameters = array()) {
		$r = $this->select($sql, $bound_parameters);
		$c = count($r);
		if ($c === 0) throw new Exception("Found 0 rows", 500);
		elseif ($c !== 1) throw new Exception("Too many rows (".(count($r)).") returned from '$sql'", 500);
		return $r[0];
	}

	/* Provide a wrapper for updates which is really just an alias for the query function. */
	function update($sql, &$bound_parameters = array()) {
		$this->query($sql, $bound_parameters);
	}
	/*
		General query. Use this when no specific results are expected. Example: attempting to delete a resource
		that may or may exist.

		$bound_parameters is an array of arrays with the 'value' member as the value to bind and the 'pdoType' as
		that parameter's PDO datatype.
	 */
	function query($sql, &$bound_parameters = array()) {
	
		// Expand any array parameters
		$this->expand_query_params($sql, $bound_parameters);
		
		$this->statement = $this->prepare($sql);
		if (!empty($bound_parameters)) {
			foreach ($bound_parameters as $key => &$param) {
				$v = $param['value']; $t = $param['pdoType'];
				if (!$this->statement->bindValue($key, $v, $t))
					throw new Exception("Unable to bind '$v' to named parameter ':$key'.", 500);
			}
		}
		$this->statement->execute();
		$this->errors();
	}

	/* Provide a wrapper for inserts which is really just an alias for the query function. */
	function insert($sql, &$bound_parameters = array()) {
		$this->query($sql, $bound_parameters);
		if ($this->statement->rowCount() === 0)
			throw new Exception('Insert failed: no rows were inserted.', 409);
	}

	/* Provide a wrapper for deletes which is really just an alias for the query function. */
	function delete($sql, &$bound_parameters = array()) {
		$this->query($sql, $bound_parameters);
		if ($this->statement->rowCount() === 0)
			throw new Exception('Delete failed: resource does not exist.', 404);
	}

	/* Return the number of rows affected by the last INSERT, DELETE, or UPDATE.  */
	function affected_rows() {
		return $this->statement->rowCount();
	}

	/* Throw error info pertaining to the last operation. */
	private function errors() {
		$e = $this->statement->errorInfo();
		if (empty($e[0]) || $e[0] === self::DB_NO_ERR) return;
		if (!empty($e[0]) && $e[0] === self::USR_DEF_DB_ERR) {
			$msg = !empty($e[2]) ? $e[2] : 'Application defined SQL error occurred.';
			throw new Exception($msg, 412);
		}
		else if (!empty($e[0]) && !empty($e[2])) {
			throw new Exception($e[2], 500);
		}
		else throw new Exception('Update failed for unknown reason.', 500);
	}
	
	/*
		Utility: expand any parameters passed in as an array (as PDO does not yet support this).
		
		For any parameter `p => array( k1 => v1, k2 => v2, ..., kn => vn)` in `$params`,
		`p` is expanded to `p_k1 => v1, p_k2 => v2, ..., p_kn => vn` all members of `$params`.
		
		For any label `:p` in `$sql`, `p` is replaced with `:p_k1, :p_k2, ..., :p_kn` in `$sql`.
	*/
	private function expand_select_params(&$sql, &$params) {

		$expanded = array(); // store expanded parameters until we're done
		$expandedKeys = array(); // store the keys of expanded arrays so we can unset them when we're done
		
		foreach ($params as $p => $arrayParam) {
		
			// only worry about non-empty arrays
			if (!is_array($arrayParam) || empty($arrayParam)) continue;
			
			$expandedKeys[] = $p;
			$names = ''; // list of labels `:p_k1, :p_k2, ..., :p_kn` with which to replace `:p` in $sql
			foreach ($arrayParam as $k => $v) {
			
				// Ensure validity of members
				if ((!empty($v) && !is_scalar($v))) {
					$t = gettype($v);
					throw new Exception("Array parameter expansion error: members of array '$p' must be scalars, but '$k' is a '$t'.", 500);
				}
				
				$expanded["{$p}_{$k}"] = $v;
				$names .= ":{$p}_{$k}, ";
			}
			
			// replace :p in $sql with $names
			$names = rtrim($names, " ,");
			$sql = str_replace(":$p", $names, $sql);
		}
		
		// Nothing expanded
		if (empty($expandedKeys)) return;
		
		// Get rid of the now expanded array params
		foreach ($expandedKeys as $v)
			unset($params[$v]);
			
		// Merge in the expanded values
		$params = array_merge($params, $expanded);
	}
	
	/*
		Utility: expand any parameters passed in as an array (as PDO does not yet support this).
		
		For any parameter `p => array('value' => array( k1 => v1, k2 => v2, ..., kn => vn), 'pdoType' => PDO::SOME_PDO_TYPE)` in `$params`,
		`p` is expanded to 
			`p_k1 => array('value' => v1, 'pdoType' => PDO::SOME_PDO_TYPE), 
			p_k2 => array('value' => v2, 'pdoType' => PDO::SOME_PDO_TYPE), 
			..., 
			p_kn => array('value' => vn, 'pdoType' => PDO::SOME_PDO_TYPE)` 
		all members of `$params`.
		
		For any label `:p` in `$sql`, `p` is replaced with `:p_k1, :p_k2, ..., :p_kn` in `$sql`.
	*/
	private function expand_query_params(&$sql, &$params) {

		$expanded = array(); // store expanded parameters until we're done
		$expandedKeys = array(); // store the keys of expanded arrays so we can unset them when we're done
		
		foreach ($params as $p => $arrayParam) {
		
			// only worry about non-empty arrays
			if (!is_array($arrayParam['value']) || empty($arrayParam['value'])) continue;
			
			$expandedKeys[] = $p;
			$names = ''; // list of labels `:p_k1, :p_k2, ..., :p_kn` with which to replace `:p` in $sql
			foreach ($arrayParam['value'] as $k => $v) {
			
				// Ensure validity of members
				if ((!empty($v) && !is_scalar($v))) {
					$t = gettype($v);
					throw new Exception("Array parameter expansion error: members of array '$p' must be scalars, but '$k' is a '$t'.", 500);
				}
				
				$expanded["{$p}_{$k}"] = array('value' => $v, 'pdoType' => $arrayParam['pdoType']);
				$names .= ":{$p}_{$k}, ";
			}
			
			// replace :p in $sql with $names
			$names = rtrim($names, " ,");
			$sql = str_replace(":$p", $names, $sql);
		}
		
		// Nothing expanded
		if (empty($expandedKeys)) return;
		
		// Get rid of the now expanded array params
		foreach ($expandedKeys as $v)
			unset($params[$v]);
			
		// Merge in the expanded values
		$params = array_merge($params, $expanded);
	}
}