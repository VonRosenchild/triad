<?php
/**
 * Triad - Lightweight MVP / HMVP Framework
 * @link http://
 * @author Marek Vavrecan, vavrecan@gmail.com
 * @copyright 2013 Marek Vavrecan
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License, version 3
 * @version 1.0.0
 */

namespace Triad;

use \Triad\Exceptions\DatabaseException;
use \PDO;

class Database
{
    /**
     * @var PDO
     */
    protected $db = null;

    protected $dns;
    protected $user;
    protected $password;

    public function __construct($dns, $user, $password) {
        $this->dns = $dns;
        $this->user = $user;
        $this->password = $password;
    }

    public function connect() {
        if (is_null($this->db)) {
            $this->db = new PDO($this->dns, $this->user, $this->password,
                array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\'', PDO::ATTR_PERSISTENT => true));
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            //  paranoid, huh, we will not need these anymore
            unset($this->user);
            unset($this->password);
        }
    }

    public function isConnected() {
        return !is_null($this->db);
    }

    public function exec($sql, $params = null) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!($return = $statement->execute($params))) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $return;
    }

    public function lastInsertId($name = null) {
        return $this->db->lastInsertId($name);
    }

    public function fetch($sql, $params = array()) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchObject($class, $sql, $params = array()) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetchObject($class);
    }

    public function fetchColumn($sql, $params = null) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetchColumn();
    }

    public function fetchAll($sql, $params = array()) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchAllColumn($sql, $params = null) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function fetchAllObject($class, $sql, $params = null) {
        $statement = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        if (!$statement->execute($params)) {
            throw new DatabaseException($statement->errorInfo());
        }

        return $statement->fetchAll(PDO::FETCH_CLASS, $class);
    }
    
    public function update($table, $id, $saveData) {
        $updateQuery = "UPDATE ".$this->escapeIdent($table)." SET ";

        $first = true;
        foreach ($saveData as $key => $value) {
            $updateQuery .= ($first ? "" : ",") . " ".$this->escapeIdent($key)." = :".$this->escapeIdent($key)."";
            $first = false;
        }

        if (is_array($id)) {
            $key = $this->escapeIdent(key($id));
            $value = current($id);

            $saveData[$key] = $value;
            $updateQuery .= " WHERE $key = :$key";
        }
        else {
            $saveData["id"] = $id;
            $updateQuery .= " WHERE id = :id";
        }

        $this->exec($updateQuery, $saveData);
    }

    public function delete($table, $id) {
        $updateQuery = "DELETE FROM " . $this->escapeIdent($table);
        $params = [];

        if (is_array($id)) {
            $key = $this->escapeIdent(key($id));
            $value = current($id);

            $params[$key] = $value;
            $updateQuery .= " WHERE $key = :$key";
        }
        else {
            $params["id"] = $id;
            $updateQuery .= " WHERE id = :id";
        }

        $this->exec($updateQuery, $params);
    }

    public function insert($table, $saveData) {
        $keys = join(",", $this->escapeIdent(array_keys($saveData)));
        $values = ":".join(", :", $this->escapeIdent(array_keys($saveData)));
        $insertQuery = "INSERT INTO " . $this->escapeIdent($table) . "(" . $keys . ") VALUES(" . $values . ")";
        $this->exec($insertQuery, $saveData);
    }

    private function escapeIdent($column) {
        if (is_array($column))
            return array_map([$this, "escapeIdent"], $column);

        $column = preg_replace('/[^A-Za-z0-9_-]+/', '', $column);
        return $column;
    }
}

class DatabaseDebug extends Database
{
    private $log = array();

    private function setUpLog($method, $sql, $params) {
        $instance = array();
        $instance["method"] = $method;
        $instance["sql"] = $sql;
        $instance["params"] = $params;
        $instance["start_time"] = microtime(true);
        return $instance;
    }

    private function tearDownLog($instance) {
        $instance["end_time"] = microtime(true);
        $instance["duration"] = $instance["end_time"] - $instance["start_time"];
        array_push($this->log, $instance);
    }

    public function getLog() {
        return $this->log;
    }

    public function connect()
    {
        parent::connect();
    }

    public function exec($sql, $params = null) {
        $instance = $this->setUpLog("exec", $sql, $params);
        $result = parent::exec($sql, $params);
        $this->tearDownLog($instance);

        return $result;
    }

    public function fetch($sql, $params = array()) {
        $instance = $this->setUpLog("fetch", $sql, $params);
        $result = parent::fetch($sql, $params);
        $this->tearDownLog($instance);

        return $result;
    }

    public function fetchSingle($sql, $params = null, $column = null) {
        $instance = $this->setUpLog("fetchSingle", $sql, $params);
        $result = parent::fetchSingle($sql, $params, $column);
        $this->tearDownLog($instance);

        return $result;
    }

    public function fetchAll($sql, $params = array()) {
        $instance = $this->setUpLog("fetchAll", $sql, $params);
        $result = parent::fetchAll($sql, $params);
        $this->tearDownLog($instance);

        return $result;
    }

    public function fetchList($sql, $params = null, $column = null) {
        $instance = $this->setUpLog("fetchList", $sql, $params);
        $result = parent::fetchList($sql, $params, $column);
        $this->tearDownLog($instance);

        return $result;
    }
    
    public function beginTransaction() {
        $this->db->beginTransaction();
    }

    public function rollBack() {
        $this->db->rollBack();
    }

    public function commit() {
        $this->db->commit();
    }

    public function inTransaction() {
        return $this->db->inTransaction();
    }
}
