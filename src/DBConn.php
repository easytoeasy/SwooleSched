<?php

namespace pzr\swoolesched;

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use ErrorException;
use pzr\schedule\Parser;

class DBConn
{
    protected $module;
    protected $conn;
    protected $queryBuilder;

    public function __construct($config)
    {
        /* 每分钟初始化一次DB连接 */
        $this->conn = $this->connection($config);
    }

    /**
     * 之前碰到过的问题
     * 1）小心太多数据库链接数
     * 2）丢失连接
     */
    protected function connection($config)
    {
        return DriverManager::getConnection($config);
    }

    public function executeCacheQuery(int $cachetime = 120, $params = [], $types = [])
    {
        $conn = $this->conn;
        $cache = new \Doctrine\Common\Cache\ArrayCache();
        $config = $conn->getConfiguration();
        $config->setResultCacheImpl($cache);
        $query = $this->queryBuilder->getSQL();
        $cache = new FilesystemCache(__DIR__ . '/cache');
        $key = md5(sprintf(
            "%s%s%s",
            $query,
            !empty($params) ? json_encode($params) : '',
            !empty($types) ? json_encode($types) : ''
        ));
        $qcp = new QueryCacheProfile($cachetime, $key, $cache);
        $stmt = $conn->executeCacheQuery($query, $params, $types, $qcp);
        $data = $stmt->fetchAll();
        /* 7.1版本不支持以下写法，7.3支持 */
        // $data = $stmt->fetchAllAssociative();
        // $stmt->free();
        
        unset($stmt, $key, $cache, $query, $config);
        return $data;
    }

    /**
     * @return QueryBuilder
     */
    public function createQueryBuilder()
    {
        return $this->queryBuilder = $this->conn->createQueryBuilder();
    }

    /**
     * @param array $params
     * @param array $types
     * @return array
     */
    public function fetchAll($params = [], $types = [])
    {
        $conn = $this->conn;
        $sql = $this->queryBuilder->getSQL();
       
        $stmt = $conn->executeQuery($sql, $params, $types);
        $rs = $stmt->fetchAll();
        /* 为了兼容我们服务器的版本不同，都用7.1的调用方式 */
        // $rs = $conn->fetchAllAssociative($sql, $params, $types);
        // $stmt->free();
        unset($stmt, $sql);
        return $rs;
    }

    /**
     * Get the value of conn
     */ 
    public function getConn()
    {
        return $this->conn;
    }


    public function __destruct()
    {
        unset($this->queryBuilder);
        $this->conn->close();
    }
}
