# Laika Single/Multi Connecton Database Model
Laika Model Singleton Database Model is a PHP-based project that implements a robust, object-oriented database management system for handling complex transactions and data manipulation tasks. Built on top of MySQL, this singleton model aims to provide a high-performance, flexible, and secure way to interact with databases in PHP applications, specifically designed to streamline billing and cloud data management systems.

# Key Features
* <b>Object-Oriented Structure</b>: Built with PHP OOP principles, ensuring code reusability, scalability, and maintainability.</br>
* <b>Custom Database and Model Classes</b>: Uses a custom Database class for managing database connections, queries, and transactions, and a Model class to represent data entities in the application.</br>
* <b>Secure Transactions</b>: Implements ACID-compliant transactions for consistent and reliable data handling.</br>
* <b>Dynamic Query Builder</b>: Supports dynamic query generation with a range of options for filters, sorting, and pagination, making it easy to create complex queries without directly writing SQL.</br>
* <b>Error Handling</b>: Comprehensive error handling and logging for tracking and debugging issues efficiently.</br>
* <b>Scalable Architecture</b>: Designed with scalability in mind, suitable for all type of PHP applications.</br>
* <b>Easy Integration</b>: Integrates seamlessly with other PHP-based applications and frameworks, allowing flexible deployment in diverse environments.</br>

## Technologies Used
* <b>PHP (Object-Oriented)</b>: Core programming language, providing OOP features for structure and maintainability.</br>
* <b>MySQL</b>: Relational database management system used for data storage, with optimized queries for faster performance.</br>
* <b>PDO (PHP Data Objects)</b>: Utilized for secure database access with prepared statements to prevent SQL injection.</br>

## Installation
Install with composer:
```bash
composer require laikait/laika-model
```
##  Connection Manager
Configure your database settings in your PHP application page top section.
To config use:

```php
use Laika\Model\Connection;

// Require Autoload File
require_once("./vendor/autoload.php");

$default_config = [
    'driver' => 'mysql', // Required
    'host' => 'localhost', // Required
    'port' => 3306 // Optional
    'database' => 'your_db_name', // Required
    'username' => 'db_username', // Required
    'password' => 'db_password' // Required
];

// Add Default Connection Manager
Connection::add(array $default_config); // DB Default Connection Details for Read & Write both

/**
 * Add Multiple Connection Manager. Default is for read, write or foreign
 */
Connection::add(array $other_config, 'other'); // DB Another Connection for Read & Write. Local or Foreign
Connection::add(array $ReadDbConfig, 'read'); // DB Connection Details for Read
Connection::add(array $WriteDbConfig, 'write'); // DB Connection Details for Write
```
## Usage
This project provides a base for any PHP application needing a reliable and efficient database model, especially useful for billing and cloud services. For detailed usage examples, please see the given method implementation below.

### Get PDO Connection
```php
// Get Default PDO Connection
$pdo = Connection::get();

// Get Read PDO Connection if Configured
$pdo = Connection::get('read');
// Get Write PDO Connection if Configured
$pdo = Connection::get('write');
// Get Other PDO Connection if Configured
$pdo = Connection::get('other');
```
Now you can execute any query by using any PDO methods.
### Get Laika Model Pre-build Methods
To use Laika Pre-build methods instead of PDO Methods you can use DB Class from Laika model.

```php
use Laika\Model\Model;

// Get Default DB Model
$model = new Model();

// Get Read DB Model if Configured
$model = new Model('read');
// Get Write DB Model if Configured
$model = new Model('write');
// Get Other DB Model if Configured
$model = new Model('other');

// Get All Columns Data from Table
$data = $model->table('table')->get();

// Get Selected Columns Data from Table
$data = $model->table('table')->select('column1,column2,column3')->get();

// Get Data from Table By Using Strings in Where Clause
$data = $model->table('table')->where(['column' => 'valjue'])->get();
// OR
// Get Data from Table By Using Array in Where Clause
$data = $model->table('table')->where(['id' => 1,'country'=>'usa'], '=', 'AND')->get();

```
