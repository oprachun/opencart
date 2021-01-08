<?php
namespace Opencart\System\Library\DB;
final class PgSQL {
	private $connection;

	public function __construct($hostname, $username, $password, $database, $port = '5432') {
		try {
			$pg = @pg_connect('host=' . $hostname . ' port=' . $port .  ' user=' . $username . ' password='	. $password . ' dbname=' . $database);
		} catch (\Exception $e) {
			throw new \Exception('Error: Could not make a database link using ' . $username . '@' . $hostname);
		}

		if ($pg) {
			$this->connection = $pg;
			pg_query($this->connection, "SET CLIENT_ENCODING TO 'UTF8'");
		}
	}

	public function query($sql) {
		$sql = str_replace(['`', ' time_zone ', ' INTERVAL 1 HOUR', '0000-00-00', 'YEAR(CURDATE())'], ['"', ' timezone ', " INTERVAL '1 HOUR'", '1970-01-01', "date_part('year', CURRENT_DATE)"], $sql);
		$pos = strpos($sql, 'LIMIT ');
		while ($pos !== false) {
			$pos += strlen('LIMIT ');
			while ($pos < strlen($sql)) {
				if ($sql[$pos] === ',') {
					$sql = substr($sql, 0, $pos) . ' OFFSET ' . substr($sql, $pos + 1);
					break;
				} elseif (!in_array($sql[$pos], [0, 1, 2, 3, 4, 5, 6, 7, 8, 9])) {
					break;
				}
				$pos++;
			}

			$pos = strpos($sql, 'LIMIT ', $pos);
		}
		$resource = pg_query($this->connection, $sql);

		if ($resource) {
			if (is_resource($resource)) {
				$i = 0;

				$data = [];

				while ($result = pg_fetch_assoc($resource)) {
					$data[$i] = $result;

					$i++;
				}

				pg_free_result($resource);

				$query = new \stdClass();
				$query->row = isset($data[0]) ? $data[0] : [];
				$query->rows = $data;
				$query->num_rows = $i;

				unset($data);

				return $query;
			} else {
				return true;
			}
		} else {
			throw new \Exception('Error: ' . pg_result_error($this->connection) . '<br />' . $sql);
		}
	}

	public function escape($value) {
		return pg_escape_string($this->connection, $value);
	}

	public function countAffected() {
		return pg_affected_rows($this->connection);
	}

	public function isConnected() {
		if (pg_connection_status($this->connection) == PGSQL_CONNECTION_OK) {
			return true;
		} else {
			return false;
		}
	}

	public function getLastId() {
		trigger_error('Not supported ' . __METHOD__ . '. Instead use RETURNING in INSERT or UPDATE sql');
	}

	public function __destruct() {
		if ($this->connection) {
			pg_close($this->connection);

			$this->connection = '';
		}
	}
}