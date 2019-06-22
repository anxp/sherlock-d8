<?php
/**
 * Created by PhpStorm.
 * User: andrey
 * Date: 2019-06-16
 * Time: 22:18
 */

namespace Drupal\sherlock_d8\CoreClasses\DatabaseManager;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

class DatabaseManager implements ContainerInjectionInterface {
  protected $mappedData = null;
  protected $selectedTable = null;

  /**
   * @var \Drupal\Core\Database\Connection $dbConnection
   */
  protected $dbConnection;

  /**
   * Constructs a new DatabaseManager object.
   * @param \Drupal\Core\Database\Connection $dbConnection
   */
  public function __construct(Connection $dbConnection) {
    $this->dbConnection = $dbConnection;
  }

  /**
   * Implementation of static method create() required to properly implement ContainerInjectionInterface
   * @param ContainerInterface $container
   * @return DatabaseManager
   */
  public static function create(ContainerInterface $container) {
    /**
     * @var \Drupal\Core\Database\Connection $dbConnection
     */
    $dbConnection = $container->get('database');
    return new static($dbConnection);
  }

  public function setData($mappedData) {
    $this->mappedData = $mappedData;
    return $this;
  }

  public function selectTable($tableName) {
    $this->selectedTable = $tableName;
    return $this;
  }

  public function insertRecord() {
    $this->dbConnection->insert($this->selectedTable)->fields($this->mappedData)->execute();
  }

  public function checkIfRecordExists() {
  }
}

