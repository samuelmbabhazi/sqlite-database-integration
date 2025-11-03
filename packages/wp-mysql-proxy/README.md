# WP MySQL Proxy
A MySQL proxy that bridges the MySQL wire protocol to a PDO-like interface.

This is a zero-dependency, pure PHP implementation of a MySQL proxy that acts as
a MySQL server, accepts MySQL-native commands, and executes them using a configurable
PDO-like driver. This allows MySQL-compatible clients to connect and run queries
against alternative database backends over the MySQL wire protocol.

Combined with the **WP SQLite Driver**, this allows MySQL-based projects to run
on SQLite.

## Usage

### CLI:

```bash
$ php mysql-proxy.php [--database <path/to/db.sqlite>] [--port <port>]

Options:
  -h, --help            Show this help message and exit.
  -d, --database=<path> The path to the SQLite database file. Default: :memory:
  -p, --port=<port>     The port to listen on. Default: 3306
```

### PHP:
```php
use WP_MySQL_Proxy\MySQL_Proxy;
use WP_MySQL_Proxy\Adapter\SQLite_Adapter;

require_once __DIR__ . '/vendor/autoload.php';

$proxy = new MySQL_Proxy(
	new SQLite_Adapter( $db_path ),
	array( 'port' => $port )
);
$proxy->start();
```
