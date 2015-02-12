<?php

class MySQL_CRUD_API {

	private $method;
	private $request;
	private $mysqli;
	private $database;
	private $whitelist;
	private $blacklist;

	private function connectDatabase($hostname,$username,$password,$database) {
		$mysqli = new mysqli($hostname,$username,$password,$database);
		if ($mysqli->connect_errno) {
			die('Connect failed: '.$mysqli->connect_error);
		}
		return $mysqli;
	}

	private function mapMethodToAction($method,$request) {
		switch ($method) {
			case 'GET': return count($request)>1?'read':'list';
			case 'PUT': return 'update';
			case 'POST': return 'create';
			case 'DELETE': return 'delete';
			default: $this->exitWith404();
		}
	}

	private function parseRequestParameter($request,$position,$characters,$default) {
		$value = isset($request[$position])?$request[$position]:$default;
		return $characters?preg_replace("/[^$characters]/",'',$value):$value;
	}

	private function parseGetParameter($name,$characters,$default) {
		$value = isset($_GET[$name])?$_GET[$name]:$default;
		return $characters?preg_replace("/[^$characters]/",'',$value):$value;
	}

	private function applyWhitelist($table,$action,$list) {
		if ($list===false) return $table;
		$list = array_filter($list, function($actions){
			return strpos($actions,$action[0])!==false;
		});
		return array_intersect($table, array_keys($list));
	}

	private function applyBlacklist($table,$action,$list) {
		if ($list===false) return $table;
		$list = array_filter($list, function($actions) use ($action) {
			return strpos($actions,$action[0])!==false;
		});
		return array_diff($table, array_keys($list));
	}

	private function applyWhitelistAndBlacklist($table, $action, $whitelist, $blacklist) {
		$table = $this->applyWhitelist($table, $action, $whitelist);
		$table = $this->applyBlacklist($table, $action, $blacklist);
		if (empty($table)) $this->exitWith404();
		return $table;
	}

	private function processTableParameter($table,$database,$mysqli) {
		$tablelist = explode(',',$table);
		$tables = array();
		foreach ($tablelist as $table) {
			$table = str_replace('*','%',$table);
			if ($result = $mysqli->query("SELECT `TABLE_NAME` FROM `INFORMATION_SCHEMA`.`TABLES` WHERE `TABLE_NAME` LIKE '$table' AND `TABLE_SCHEMA` = '$database'")) {
				while ($row = $result->fetch_row()) $tables[] = $row[0];
				$result->close();
			}
		}
		return $tables;
	}

	private function findPrimaryKey($table,$database,$mysqli) {
		$keys = array();
		if ($result = $mysqli->query("SELECT `COLUMN_NAME` FROM `INFORMATION_SCHEMA`.`COLUMNS` WHERE `COLUMN_KEY` = 'PRI' AND `TABLE_NAME` = '$table[0]' AND `TABLE_SCHEMA` = '$database'")) {
			while ($row = $result->fetch_row()) $keys[] = $row[0];
			$result->close();
		}
		return count($keys)?$keys[0]:false;
	}

	private function exitWith404() {
		die(header("Content-Type:",true,404));
	}

	private function startOutput($callback) {
		if ($callback) {
			header("Content-Type: application/javascript");
			echo $callback.'(';
		} else {
			header("Content-Type: application/json");
		}
	}

	private function endOutput($callback) {
		if ($callback) {
			echo ');';
		}
	}

	private function processKeyParameter($key,$table,$database,$mysqli) {
		if ($key) {
			$key = array($key,$this->findPrimaryKey($table,$database,$mysqli));
			if ($key[1]===false) $this->exitWith404();
		}
		return $key;
	}

	private function processFilterParameter($filter,$match,$mysqli) {
		if ($filter) {
			$filter = explode(':',$filter,2);
			if (count($filter)==2) {
				$filter[0] = preg_replace('/[^a-zA-Z0-9\-_]/','',$filter[0]);
				if ($match=='in') {
					$filter[1] = implode("','",array_map(function($v){ return preg_replace('/[^a-zA-Z0-9\-]/','',$v); },explode(',',$filter[1])));
				} else {
					$filter[1] = $mysqli->real_escape_string($filter[1]);
				}
				$filter[2] = 'LIKE';
				if ($match=='any'||$match=='start') $filter[1] .= '%';
				if ($match=='any'||$match=='end') $filter[1] = '%'.$filter[1];
				if ($match=='exact') $filter[2] = '=';
				if ($match=='lower') $filter[2] = '<';
				if ($match=='upto') $filter[2] = '<=';
				if ($match=='from') $filter[2] = '>=';
				if ($match=='higher') $filter[2] = '>';
				if ($match=='in') $filter[2] = 'IN';
				$filter[1]="'$filter[1]'";
				if ($filter[2]=='IN') $filter[1]="($filter[1])";
			} else {
				$filter = false;
			}
		}
		return $filter;
	}

	private function processPageParameter($page) {
		if ($page) {
			$page = explode(',',$page,2);
			if (count($page)<2) $page[1]=20;
			$page[0] = ($page[0]-1)*$page[1];
		}
		return $page;
	}

	private function retrieveObject($key,$table,$mysqli) {
		if (!$key) return false;
		if ($result = $mysqli->query("SELECT * FROM `$table[0]` WHERE `$key[1]` = '$key[0]'")) {
			$object = $result->fetch_assoc();
			$result->close();
		}
		return $object;
	}

	private function createObject($input,$table,$mysqli) {
		if (!$input) return false;
		$keys = implode('`,`',array_map(function($v){ return preg_replace('/[^a-zA-Z0-9\-_]/','',$v); },array_keys((array)$input)));
		$values = implode("','",array_map(function($v) use ($mysqli){ return $mysqli->real_escape_string($v); },array_values((array)$input)));
		$mysqli->query("INSERT INTO `$table[0]` (`$keys`) VALUES ('$values')");
		return $mysqli->insert_id;
	}

	private function updateObject($key,$input,$table,$mysqli) {
		if (!$input) return false;
		$sql = "UPDATE `$table[0]` SET ";
		foreach (array_keys((array)$input) as $i=>$k) {
			if ($i) $sql .= ",";
			$v = $input->$k;
			$sql .= "`$k`='$v'";
		}
		$sql .= " WHERE `$key[1]`='$key[0]'";
		$mysqli->query($sql);
		return $mysqli->affected_rows;
	}

	private function deleteObject($key,$table,$mysqli) {
		$mysqli->query("DELETE FROM `$table[0]` WHERE `$key[1]`='$key[0]'");
		return $mysqli->affected_rows;
	}

	private function getParameters($method, $request, $database, $whitelist, $blacklist, $mysqli) {
		$action   = $this->mapMethodToAction($method, $request);
		$table    = $this->parseRequestParameter($request, 0, 'a-zA-Z0-9\-_*,', '*');
		$key      = $this->parseRequestParameter($request, 1, 'a-zA-Z0-9\-,', false); // auto-increment or uuid
		$callback = $this->parseGetParameter('callback', 'a-zA-Z0-9\-_', false);
		$page     = $this->parseGetParameter('page', '0-9,', false);
		$filter   = $this->parseGetParameter('filter', false, 'start');
		$match    = $this->parseGetParameter('match', 'a-z', false);

		$table  = $this->processTableParameter($table,$database,$mysqli);
		$key    = $this->processKeyParameter($key,$table,$database,$mysqli);
		$filter = $this->processFilterParameter($filter,$match,$mysqli);
		$page   = $this->processPageParameter($page);

		$table  = $this->applyWhitelistAndBlacklist($table,$action,$whitelist,$blacklist);

		$object = $this->retrieveObject($key,$table,$mysqli);
		$input  = json_decode(file_get_contents('php://input'));

		return compact('action','table','key','callback','page','filter','match','mysqli','object','input');
	}

	private function listCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		echo '{';
		$tables = $table;
		foreach ($tables as $t=>$table) {
			$count = false;
			if ($t>0) echo ',';
			echo '"'.$table.'":{';
			if ($t==0 && is_array($page)) {
				$sql = "SELECT COUNT(*) FROM `$table`";
				if (is_array($filter)) $sql .= " WHERE `$filter[0]` $filter[2] $filter[1]";
				if ($result = $mysqli->query($sql)) {
					$pages = $result->fetch_row();
					$count = $pages[0];
				}
			}
			echo '"columns":';
			$sql = "SELECT * FROM `$table`";
			if ($t==0 && is_array($filter)) $sql .= " WHERE `$filter[0]` $filter[2] $filter[1]";
			if ($t==0 && is_array($page)) $sql .= " LIMIT $page[1] OFFSET $page[0]";
			if ($result = $mysqli->query($sql)) {
				$fields = array();
				foreach ($result->fetch_fields() as $field) $fields[] = $field->name;
				echo json_encode($fields);
				echo ',"records":[';
				$first_row = true;
				while ($row = $result->fetch_row()) {
					if ($first_row) $first_row = false;
					else echo ',';
					echo json_encode($row);
				}
				$result->close();
			}
			if ($count) echo ',"results":'.$count;
			echo ']}';
		}
		echo '}';
		$this->endOutput($callback);
	}

	private function readCommand($parameters) {
		extract($parameters);
		if (!$object) $this->exitWith404();
		$this->startOutput($callback);
		echo json_encode($object);
		$this->endOutput($callback);
	}

	private function createCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404();
		$this->startOutput($callback);
		echo json_encode($this->createObject($input,$table,$mysqli));
		$this->endOutput($callback);
	}

	private function updateCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404();
		$this->startOutput($callback);
		echo json_encode($this->updateObject($key,$input,$table,$mysqli));
		$this->endOutput($callback);
	}

	private function deleteCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		echo json_encode($this->deleteObject($key,$table,$mysqli));
		$this->endOutput($callback);
	}

	public function __construct($hostname,$username,$password,$database,$whitelist,$blacklist) {
		$this->method = $_SERVER['REQUEST_METHOD'];
		$this->request = explode("/", substr(@$_SERVER['PATH_INFO'], 1));
		$this->mysqli = $this->connectDatabase($hostname,$username,$password,$database);
		$this->database = $database;
		$this->whitelist = $whitelist;
		$this->blacklist = $blacklist;
	}

	public function executeCommand() {
		$parameters = $this->getParameters($this->method, $this->request, $this->database, $this->whitelist, $this->blacklist, $this->mysqli);
		switch($parameters['action']){
			case 'list': $this->listCommand($parameters); break;
			case 'read': $this->readCommand($parameters); break;
			case 'create': $this->readCommand($parameters); break;
			case 'update': $this->readCommand($parameters); break;
			case 'delete': $this->readCommand($parameters); break;
		}
	}
}

//$api = new MySQL_CRUD_API("localhost","user","pass","db",false,array("users"=>"crudl"));
//$api->executeCommand();