<?php


class DB
{
    private $instance = null;

    public function __construct() {
        global $config;
        
        if ($this->instance === null)
        {
            $opt  = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            );
            $dsn = $config['dsn'];
            $this->instance = new PDO($dsn, $config['username'], $config['password'], $opt);
        }
    }
    
    
    public function __destruct() {
        
        $this->instance = null;
    }

    
    public function __call($method, $args)
    {
        return call_user_func_array(array($this->instance, $method), $args);
    }

    public function query($sql, $args = array())
    {
        $stmt = $this->instance->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }
}


?>