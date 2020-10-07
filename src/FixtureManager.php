<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpTestSuiteLight;

use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use CakephpTestSuiteLight\Sniffer\BaseTableSniffer;
use CakephpTestSuiteLight\Sniffer\MysqlTableSniffer;
use CakephpTestSuiteLight\Sniffer\PostgresTableSniffer;
use CakephpTestSuiteLight\Sniffer\SqliteTableSniffer;
use function strpos;

/**
 * Class FixtureManager
 * @package CakephpTestSuiteLight
 */
class FixtureManager
{
    private static $_configIsLoaded = false;

    /**
     * Was this instance already initialized?
     *
     * @var bool
     */
    protected $_initialized = false;

    /**
     * FixtureManager constructor.
     * The config file fixture_factories is being loaded
     */
    public function __construct()
    {
        $this->initDb();
        $this->loadConfig();
    }

    /**
     * @param string $name
     * @return ConnectionInterface
     */
    public function getConnection($name = 'test')
    {
        return ConnectionManager::get($name);
    }

    /**
     * Initializes this class with a DataSource object to use as default for all fixtures
     *
     * @return void
     */
    protected function _initDb()
    {
        if ($this->_initialized) {
            return;
        }
        $this->_aliasConnections();
        $this->_initialized = true;
    }

    public function initDb()
    {
        $this->_initDb();
    }

    /**
     * Add aliases for all non test prefixed connections.
     *
     * This allows models to use the test connections without
     * a pile of configuration work.
     *
     * @return void
     */
    protected function _aliasConnections()
    {
        $connections = ConnectionManager::configured();
        ConnectionManager::alias('test', 'default');
        $map = [];
        foreach ($connections as $connection) {
            if ($connection === 'test' || $connection === 'default') {
                continue;
            }
            if (isset($map[$connection])) {
                continue;
            }
            if (strpos($connection, 'test_') === 0) {
                $map[$connection] = substr($connection, 5);
            } else {
                $map['test_' . $connection] = $connection;
            }
        }
        foreach ($map as $testConnection => $normal) {
            ConnectionManager::alias($testConnection, $normal);
        }
    }

    public function aliasConnections()
    {
        $this->_aliasConnections();
    }

    public function getSniffer(string $connectionName): BaseTableSniffer
    {
        $connection = $this->getConnection($connectionName);
        $driver = $connection->config()['driver'];
        try {
            $snifferName = Configure::readOrFail('TestSuiteLightSniffers.' . $driver);
        } catch (\RuntimeException $e) {
            throw new \PHPUnit\Framework\Exception("The DB driver $driver is not being supported");
        }
        /** @var BaseTableSniffer $snifferName */
        return new $snifferName($connection);
    }

    /**
     * Scan all Test connections and truncate the dirty tables
     */
    public function truncateDirtyTablesForAllTestConnections()
    {
        $connections = ConnectionManager::configured();

        foreach ($connections as $connectionName) {
            $ignoredConnections = Configure::read('TestFixtureIgnoredConnections', []);
            if ($connectionName === 'test_debug_kit' || in_array($connectionName, $ignoredConnections)) {
                // CakePHP 4 solves a DebugKit issue by creating an Sqlite connection
                // in tests/bootstrap.php. This connection should be ignored.
            } elseif ($connectionName === 'test' || strpos($connectionName, 'test_') === 0) {
                $this->getSniffer($connectionName)->truncateDirtyTables();
            }
        }
    }

    /**
     * Load the mapping between the database drivers
     * and the table truncators.
     * Add your own truncators for a driver not being covered by
     * the package in your fixture-factories.php config file
     */
    public function loadConfig()
    {
        if (!self::$_configIsLoaded) {
            Configure::write([
                'TestSuiteLightSniffers' => $this->getDefaultTableSniffers()
            ]);
            try {
                Configure::load('test_suite_light');
            } catch (Exception $exception) {}
            self::$_configIsLoaded = true;
        }
    }

    /**
     * Table truncators provided by the package
     * @return array
     */
    private function getDefaultTableSniffers()
    {
        return [
            \Cake\Database\Driver\Mysql::class => MysqlTableSniffer::class,
            \Cake\Database\Driver\Sqlite::class => SqliteTableSniffer::class,
            \Cake\Database\Driver\Postgres::class => PostgresTableSniffer::class,
        ];
    }

    /**
     * Get the appropriate truncator and drop all tables
     * @param string $connectionName
     */
    public function dropTables(string $connectionName)
    {
        $this->getSniffer($connectionName)->dropAllTables();
    }
}
