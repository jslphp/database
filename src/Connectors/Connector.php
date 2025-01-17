<?php

namespace Jsl\Database\Connectors;

use Jsl\Database\Exception\ConnectionException;
use PDO;

class Connector
{

    /**
     * The default PDO connection options.
     *
     * @var array
     */
    protected $options = array(
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    );

    /**
     * Get the PDO options based on the configuration.
     *
     * @param  array $config
     * @return array
     */
    public function getOptions(array $config)
    {
        $options = isset($config['options']) ? $config['options'] : array();

        return array_diff_key($this->options, $options) + $options;
    }

    /**
     * Create a new PDO connection.
     *
     * @param  string $dsn
     * @param  array $config
     * @param  array $options
     * @return \PDO
     */
    public function createConnection($dsn, array $config, array $options)
    {
        $username = isset($config['username']) ? $config['username'] : null;

        $password = isset($config['password']) ? $config['password'] : null;

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new ConnectionException("Connection to '$dsn' failed: " . $e->getMessage(), $e);
        }
    }

    /**
     * Get the default PDO connection options.
     *
     * @return array
     */
    public function getDefaultOptions()
    {
        return $this->options;
    }

    /**
     * Set the default PDO connection options.
     *
     * @param  array $options
     * @return void
     */
    public function setDefaultOptions(array $options)
    {
        $this->options = $options;
    }
}
