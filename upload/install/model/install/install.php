<?php
namespace Opencart\Application\Model\Install;
class Install extends \Opencart\System\Engine\Model {
	private $pg_enums = [];
	private $pg_enums_loaded = false;

	public function database($data) {
		$db = new \Opencart\System\Library\DB($data['db_driver'], html_entity_decode($data['db_hostname'], ENT_QUOTES, 'UTF-8'), html_entity_decode($data['db_username'], ENT_QUOTES, 'UTF-8'), html_entity_decode($data['db_password'], ENT_QUOTES, 'UTF-8'), html_entity_decode($data['db_database'], ENT_QUOTES, 'UTF-8'), $data['db_port']);

		// Structure
		$this->load->helper('db_schema');

		$tables = db_schema();

		$is_pgsql = $data['db_driver'] === 'pgsql';
		if ($is_pgsql) {
			$this->pgPrepare($db);
		}

		foreach ($tables as $table) {
			if ($is_pgsql) {
				$table_query = $db->query("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = '" . $data['db_prefix'] . "{$table['name']}' AND tableowner = '{$data['db_username']}'");
			} else {
				$table_query = $db->query("SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . $data['db_database'] . "' AND TABLE_NAME = '" . $data['db_prefix'] . $table['name'] . "'");
			}

			if ($table_query->num_rows) {
				$db->query("DROP TABLE `" . $data['db_prefix'] . $table['name'] . "`");
			}

			$sql = "CREATE TABLE `" . $data['db_prefix'] . $table['name'] . "` (" . "\n";

			foreach ($table['field'] as $field) {
				if ($is_pgsql) {
					$sql .= "  `" . $field['name'] . "` " . $this->pgTypeGet($db, $field['type'], !empty($field['auto_increment'])) . (!empty($field['not_null']) ? " NOT NULL" : "") . (isset($field['default']) ? " DEFAULT '" . $db->escape($field['default']) . "'" : "") . ",\n";
				} else {
					$sql .= "  `" . $field['name'] . "` " . $field['type'] . (!empty($field['not_null']) ? " NOT NULL" : "") . (isset($field['default']) ? " DEFAULT '" . $db->escape($field['default']) . "'" : "") . (!empty($field['auto_increment']) ? " AUTO_INCREMENT" : "") . ",\n";
				}
			}

			if (isset($table['primary'])) {
				$primary_data = [];

				foreach ($table['primary'] as $primary) {
					$primary_data[] = "`" . $primary . "`";
				}

				if ($is_pgsql) {
					$sql .= "  CONSTRAINT pk_" . $data['db_prefix'] . $table['name'];
				}
				$sql .= "  PRIMARY KEY (" . implode(",", $primary_data) . "),\n";
			}

			if (!$is_pgsql && isset($table['index'])) {
				foreach ($table['index'] as $index) {
					$index_data = [];

					foreach ($index['key'] as $key) {
						$index_data[] = "`" . $key . "`";
					}

					$sql .= "  KEY `" . $index['name'] . "` (" . implode(",", $index_data) . "),\n";
				}
			}

			$sql = rtrim($sql, ",\n") . "\n";
			if ($is_pgsql) {
				$sql .= ");\n";
			} else {
				$sql .= ") ENGINE=" . $table['engine'] . " CHARSET=" . $table['charset'] . " COLLATE=" . $table['collate'] . ";\n";
			}

			$db->query($sql);

			if ($is_pgsql && isset($table['index'])) {
				foreach ($table['index'] as $index) {
					$index_data = [];

					foreach ($index['key'] as $key) {
						$index_data[] = "`" . $key . "`";
					}

					$db->query("CREATE INDEX ix_" . $data['db_prefix'] . $table['name'] . "__{$index['name']} ON " . $data['db_prefix'] . $table['name'] . " (" . implode(",", $index_data) . ")");
				}
			}
		}
		// Data
		$lines = file(DIR_APPLICATION . 'opencart.sql', FILE_IGNORE_NEW_LINES);

		if ($lines) {
			$sql = '';

			$start = false;

			foreach ($lines as $line) {
				if (substr($line, 0, 12) == 'INSERT INTO ') {
					$sql = '';

					$start = true;
				}

				if ($start) {
					$sql .= $line;
				}

				if (substr($line, -2) == ');') {
					if ($is_pgsql) {
						$sql = str_replace([" '"], [" e'"], $sql);
					}
					$db->query(str_replace("INSERT INTO `oc_", "INSERT INTO `" . $data['db_prefix'], $sql));

					$start = false;
				}
			}
		}

		if (!$is_pgsql) {
			$db->query("SET CHARACTER SET utf8");
		}

		$db->query("UPDATE " . $data['db_prefix'] . "user SET user_group_id = '1', username = '" . $db->escape($data['username']) . "', password = '" . $db->escape(password_hash(html_entity_decode($data['password'], ENT_QUOTES, 'UTF-8'), PASSWORD_DEFAULT)) . "', firstname = 'John', lastname = 'Doe', email = '" . $db->escape($data['email']) . "', status = '1', date_added = NOW() WHERE user_id = 1");

		$db->query("UPDATE " . $data['db_prefix'] . "setting SET code = 'config', value = '" . $db->escape($data['email']) . "' WHERE `key` = 'config_email'");
		$db->query("UPDATE " . $data['db_prefix'] . "setting SET code = 'config', value = '" . $db->escape(token(1024)) . "' WHERE `key` = 'config_encryption'");

		$db->query("UPDATE " . $data['db_prefix'] . "product SET viewed = '0'");

		$sql = "INSERT INTO " . $data['db_prefix'] . "api(username, `key`, status, date_added, date_modified) VALUES('Default', '" . $db->escape(token(256)) . "', 1, NOW(), NOW())";
		if ($is_pgsql) {
			$query = $db->query($sql . ' RETURNING api_id');
			$api_id = $query->row['api_id'];
		} else {
			$db->query($sql);
			$api_id = $db->getLastId();
		}

		$db->query("UPDATE " . $data['db_prefix'] . "setting SET code = 'config', value = '" . (int)$api_id . "' WHERE `key` = 'config_api_id'");

		// set the current years prefix
		$db->query("UPDATE " . $data['db_prefix'] . "setting SET value = 'INV-" . date('Y') . "-00' WHERE `key` = 'config_invoice_prefix'");
	}

	private function pgPrepare($db) {
		$sqls = [
			'CREATE OR REPLACE FUNCTION date_sub(adate timestamptz, ainterval interval) RETURNS TIMESTAMP WITHOUT TIME ZONE AS $body$ begin return adate - ainterval; end; $body$ LANGUAGE \'plpgsql\'',
			'CREATE OR REPLACE FUNCTION lcase(atext text) RETURNS text AS $body$ begin return lower(atext); end; $body$ LANGUAGE \'plpgsql\''
		];
		foreach ($sqls as $sql) {
			$db->query($sql);
		}
	}

	private function pgTypeGet($db, $field_type, $autoincrement) {
		$pos = strpos($field_type, '(');
		if ($pos === false) {
			$type = $field_type;
			$size = '';
		} else {
			$type = substr($field_type, 0, $pos);
			$size = substr($field_type, $pos + 1, strlen($field_type) - $pos - 2);
		}

		if ($autoincrement) {
			switch ($type) {
			case 'int':
				return 'serial';
			case 'tinyint':
				return 'smallserial';
			default:
				trigger_error('not supported autoincrement for type ' . $field_type);
			}
		}

		switch ($type) {
		case 'enum':
			return $this->pgEnumCreate($db, $field_type);
		case 'char':
		case 'date':
		case 'text':
		case 'varchar':
			return $field_type;
		case 'mediumtext':
			return 'text';
		case 'datetime':
			return 'timestamp';
		case 'double':
			return 'double precision';
		case 'decimal':
			return "numeric($size)";
		case 'int':
			return /*$size == 1 ? 'bool' : */'int';
		case 'smallint':
		case 'tinyint':
			return /*$size == 1 ? 'bool' : */'smallint';
		default:
			trigger_error('not supported type ' . $field_type);
		}
	}

	private function pgEnumCreate($db, $enum) {
		$name = 'enum_' . hash('crc32', $enum);
		if (!$this->pg_enums_loaded) {
			$query = $db->query("SELECT typname FROM pg_type WHERE typtype = 'e'");
			foreach ($query->rows as $row) {
				$this->pg_enums[] = $row['typname'];
			}
			$this->pg_enums_loaded = true;
		}
		if (!in_array($name, $this->pg_enums)) {
			$db->query("CREATE TYPE $name AS " . $enum);
			$this->pg_enums[] = $name;
		}
		return $name;
	}
}
