<?php
/**
 * Created by Yosef(Vlad) Kaminskyi
 * Mailto: moledet[at]ukr.net
 * Version: 0.1
 * Date: 29/01/2017
 * Time: 14:29
 * Dependencies: PHP-SQL-Parser https://github.com/greenlion/PHP-SQL-Parser
 */


namespace Log;

require_once __DIR__.'/../PHP-SQL-Parser/src/PHPSQLParser.php';

/**
 * Class DatabaseChangeLog
 * Log change of tables[columns] from config into database connection.
 *
 * @package Log
 */
class DatabaseChangeLog
{
    static private $instance = null;

    private $userId = 0;
    private $systemName = 'CRM';

    /**
     * Array of config tables that need been log.
     * If empty - all tables will be in change log.
     *
     * @example
     *   [
     *      'user'=>[
     *          'insert'=>['login','name','password']
     *          'delete'=>'all',
     *          'update'=>['login','name']
     *       ],
     *      'customers'=>'all',
     *    ]
     *
     * @var array
     */
    private $logTablesConfig = [];

    /** @var \PDO */
    private $connection = null;

    private $connectionString = null;

    private function __construct() { /* ... @return Singleton */ }
    private function __clone() { /* ... @return Singleton */ }
    private function __wakeup() { /* ... @return Singleton */ }

    static public function getInstance()
    {
        return
            self::$instance === null
                ? self::$instance = new self()
                : self::$instance;
    }

    public function setUserId($userId)
    {
        return $this->userId=  $userId;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return array
     */
    public function getLogTablesConfig()
    {
        return $this->logTablesConfig;
    }

    /**
     * @param array $logTablesConfig
     */
    public function setLogTablesConfig($logTablesConfig)
    {
        $this->logTablesConfig = $logTablesConfig;
    }


    /**
     * @return string
     */
    public function getSystemName()
    {
        return $this->systemName;
    }

    /**
     * @param string $systemName
     */
    public function setSystemName($systemName)
    {
        $this->systemName = $systemName;
    }

    /**
     * Set connection from config array for PDO
     *
     * @param array $config config with fields database(mysq,pgsql..), host, port, dbname, user, password, charset
     * @return bool|null|\PDO
     */
    public function setConnection($config)
    {
        if(!isset($config['charset'])){
            $config['charset'] = 'utf8';
        }

        $connectionString = $config['database'].':host='.$config['host'].';port='.$config['port'].';dbname='.$config['dbname'].';charset='.$config['charset'];

        if($this->connectionString == $connectionString){
            return false;
        }

        $options = array(
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        );

        $this->connectionString = $connectionString;
        $this->connection = new \PDO($connectionString,$config['user'],$config['password'],$options);

        return $this->connection;
    }

    /**
     * Function to get the user IP address
     *
     * @return string
     */
    public function getUserIP() {

        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if(isset($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
        else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if(isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if(isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        return $ipaddress;
    }

    /**
     * Get userId, user agent and ip IP this request.
     *
     * @return array
     */
    private function getUserData()
    {
        return [
          'userId' => $this->getUserId(),
          'ip'     => $this->getUserIP(),
          'userAgent' => $_SERVER['HTTP_USER_AGENT'],

        ];
    }

    /**
     * Log delete query.
     *
     * @param $parsed
     */
    private function logDelete($parsed)
    {
        $logData = $this->getUserData();
        $logData['action'] = 'delete';

        if($parsed['FROM'][0]['expr_type']=='table'){
            $logData['table'] = $parsed['FROM'][0]['table'];
        }else{
            $logData['table'] = $parsed['FROM'][0]['base_expr'];
        }

        $logData['columnReference'] = $parsed['WHERE'][0]['base_expr'];
        $logData['operatorReference'] = $parsed['WHERE'][1]['base_expr'];
        $logData['valueReference'] = $parsed['WHERE'][2]['base_expr'];

        $this->saveLog($logData);
    }

    /**
     * Get PDO connection
     *
     * @return \PDO
     * @throws \Exception
     */
    public function getConnection()
    {
        if($this->connection == null){
            throw new \Exception("Need config PDO connection for DatabaseChangeLog before save log");
        }

        return $this->connection;
    }

    /**
     * Check if table/column need log by config.
     *
     * @param array $data action|table|column
     * @return bool
     */
    private function checkTableConfig($data)
    {
        if(empty($this->logTablesConfig)){
            return true;
        }

        if(!isset($this->logTablesConfig[$data['table']])){
            return false;
        }

        if($this->logTablesConfig[$data['table']]=='all'){
            return true;
        }


        if(!isset($this->logTablesConfig[$data['table']][$data['action']])){
            return false;
        }

        if($this->logTablesConfig[$data['table']][$data['action']]=='all'){
            return true;
        }

        if(in_array($data['column'],(array)$this->logTablesConfig[$data['table']][$data['action']])){
            return true;
        }

        return false;
    }

    /**
     * Save log data to database.
     *
     * @param array $data [action,table,column,newValue,columnReference,operatorReference,valueReference, userId, ip, userAgent]
     * @return bool status
     */
    private function saveLog($data)
    {

        if(!$this->checkTableConfig($data)){
            return false;
        }

        switch ($data['action']){
            case 'update':
                $subQuery = "SELECT {$data['table']}.{$data['column']}
                             FROM  {$data['table']}
                             WHERE {$data['table']}.{$data['columnReference']}  {$data['operatorReference']}  '{$data['valueReference']}' 
                             LIMIT 1";//prevent errors on multi were conditions TODO: use mysql:GROUP_CONCAT or pgsql:array_to_string(array_agg(column), ',')

                 $sql = "INSERT INTO data_change_log (data_change_log.action,data_change_log.table,data_change_log.column,newValue,columnReference,operatorReference,valueReference,userId,ip,userAgent, system, oldValue) ".
                        "VALUES                      (:action,:table,:column,:newValue,:columnReference,:operatorReference,:valueReference,:userId,:ip,:userAgent,'{$this->getSystemName()}',($subQuery));";

                break;
            case 'delete':
                $sql = "INSERT INTO data_change_log (data_change_log.action,data_change_log.table,columnReference,operatorReference,valueReference,userId,ip,userAgent, system) ".
                       "VALUES                      (:action,:table,:columnReference,:operatorReference,:valueReference,:userId,:ip,:userAgent,'{$this->getSystemName()}');";

                break;
            case 'insert':

                $sql = "INSERT INTO data_change_log (data_change_log.action,data_change_log.table,data_change_log.column,newValue,userId,ip,userAgent, system) ".
                        "VALUES                     (:action,:table,:column,:newValue,:userId,:ip,:userAgent,'{$this->getSystemName()}');";

                break;
        }

        $query = $this->getConnection()->prepare($sql);

        return $query->execute($data);
    }

    /**
     * Log single updated value.
     *
     * @param $value
     * @param $logData
     */
    private function logValueUpdate($value,$logData)
    {
        $logData['column'] = $value['sub_tree'][0]['base_expr'];
        $logData['newValue'] = $value['sub_tree'][2]['base_expr'];

        $this->saveLog($logData);
    }

    /**
     * Log of update query.
     *
     * @param $parsed
     */
    private function logUpdate($parsed)
    {
        $logData = $this->getUserData();
        $logData['action'] = 'update';

        if($parsed['UPDATE'][0]['expr_type']=='table'){
            $logData['table'] = $parsed['UPDATE'][0]['table'];
        }else{
            $logData['table'] = $parsed['UPDATE'][0]['base_expr'];
        }

        $logData['columnReference'] = $parsed['WHERE'][0]['base_expr'];
        $logData['operatorReference'] = $parsed['WHERE'][1]['base_expr'];
        $logData['valueReference'] = $parsed['WHERE'][2]['base_expr'];


        foreach ($parsed['SET'] as $value){
            $this->logValueUpdate($value,$logData);
        }

    }

    /**
     * Log single insert value.
     *
     * @param $column
     * @param $value
     * @param $logData
     */
    private function logValueInsert($column,$value,$logData)
    {
        $logData['column'] = $column['base_expr'];
        $logData['newValue'] = $value['base_expr'];

        $this->saveLog($logData);
    }

    /**
     * Log insert query.
     *
     * @param $parsed
     */
    private function logInsert($parsed)
    {
        $logData = $this->getUserData();
        $logData['action'] = 'insert';

        $logData['table'] = $parsed['INSERT'][0]['table'];

        foreach ($parsed['INSERT'][0]['columns'] as $num => $column){
            $this->logValueInsert($column,$parsed['VALUES'][0]['data'][$num],$logData);
        }

    }

    /**
     * Replaces any parameter placeholders in a query with the value of that
     * parameter. Useful for debugging. Assumes anonymous parameters from
     * $params are are in the same order as specified in $query
     *
     * @param string $query The sql query with parameter placeholders
     * @param array $params The array of substitution parameters
     * @return string The interpolated query
     */
    private function interpolateQuery($query, $params) {
        $keys = array();
        $values = $params;

        # build a regular expression for each parameter
        foreach ($params as $key => $value) {

            if (is_string($key)) {
                $keys[] = '/:'.$key.'/';
            } else {
                $keys[] = '/[?]/';
            }

            if (is_string($value))
                $values[$key] =$value;

            if (is_array($value))
                $values[$key] = implode("','", $value);

            if (is_null($value))
                $values[$key] = 'NULL';

            if ($value instanceof \DateTime)
                $values[$key] = $value->format('YYYY-MM-DD HH:MM:SS');
        }

        $query = preg_replace($keys, $values, $query, 1, $count);

        return $query;
    }

    /**
     * Log changes by sql query
     *
     * @param string $sql query to parse
     * @param array|null $params pdo extract params
     */
    public function log($sql,$params = null)
    {

        if($params){
            $sql = $this->interpolateQuery($sql,$params);
        }

        $parser = new \PHPSQLParser();
        $parsed = $parser->parse($sql);


        if(isset($parsed['DELETE'])){
            $this->logDelete($parsed);
        }

        if(isset($parsed['INSERT'])){
            $this->logInsert($parsed);
        }

        if(isset($parsed['UPDATE'])){
            $this->logUpdate($parsed);
        }

    }
}