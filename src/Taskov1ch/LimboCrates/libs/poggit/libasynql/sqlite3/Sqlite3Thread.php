<?php

/*
 * libasynql
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace Taskov1ch\LimboCrates\libs\poggit\libasynql\sqlite3;

use Closure;
use ErrorException;
use Exception;
use InvalidArgumentException;
use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use SQLite3;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\base\QueryRecvQueue;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\base\QuerySendQueue;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\base\SqlSlaveThread;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\result\SqlChangeResult;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\result\SqlColumnInfo;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\result\SqlInsertResult;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\result\SqlSelectResult;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\SqlError;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\SqlResult;
use Taskov1ch\LimboCrates\libs\poggit\libasynql\SqlThread;
use function assert;
use function is_array;
use const INF;
use const NAN;
use const SQLITE3_ASSOC;
use const SQLITE3_BLOB;
use const SQLITE3_FLOAT;
use const SQLITE3_INTEGER;
use const SQLITE3_NULL;
use const SQLITE3_TEXT;

class Sqlite3Thread extends SqlSlaveThread
{
	/** @var string */
	private $path;

	public static function createFactory(string $path) : Closure
	{
		return function (SleeperHandlerEntry $entry, QuerySendQueue $send, QueryRecvQueue $recv) use ($path) {
			return new Sqlite3Thread($path, $entry, $send, $recv);
		};
	}

	public function __construct(string $path, SleeperHandlerEntry $entry, QuerySendQueue $send = null, QueryRecvQueue $recv = null)
	{
		$this->path = $path;
		parent::__construct($entry, $send, $recv);
	}

	protected function createConn(&$sqlite) : ?string
	{
		try {
			$sqlite = new SQLite3($this->path);
			$sqlite->busyTimeout(60000); // default value in SQLite2
			return null;
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}

	protected function executeQuery($sqlite, int $mode, string $query, array $params) : SqlResult
	{
		assert($sqlite instanceof SQLite3);
		try {
			$stmt = ErrorToExceptionHandler::trapAndRemoveFalse(fn () => $sqlite->prepare($query));
		} catch (ErrorException) {
			throw new SqlError(SqlError::STAGE_PREPARE, $sqlite->lastErrorMsg(), $query, $params);
		}
		foreach ($params as $paramName => $param) {
			$bind = $stmt->bindValue($paramName, $param);
			if (!$bind) {
				throw new SqlError(SqlError::STAGE_PREPARE, "when binding $paramName: " . $sqlite->lastErrorMsg(), $query, $params);
			}
		}
		try {
			$result = ErrorToExceptionHandler::trapAndRemoveFalse(fn () => $stmt->execute());
		} catch (ErrorException) {
			throw new SqlError(SqlError::STAGE_EXECUTE, $sqlite->lastErrorMsg(), $query, $params);
		}
		switch ($mode) {
			case SqlThread::MODE_GENERIC:
				$ret = new SqlResult();
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_CHANGE:
				$ret = new SqlChangeResult($sqlite->changes());
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_INSERT:
				$ret = new SqlInsertResult($sqlite->changes(), $sqlite->lastInsertRowID());
				$result->finalize();
				$stmt->close();
				return $ret;
			case SqlThread::MODE_SELECT:
				/** @var SqlColumnInfo[] $colInfo */
				$colInfo = [];
				$rows = [];
				while (is_array($row = $result->fetchArray(SQLITE3_ASSOC))) {
					foreach (array_keys($row) as $i => $columnName) {
						static $columnTypeMap = [
							SQLITE3_INTEGER => SqlColumnInfo::TYPE_INT,
							SQLITE3_FLOAT => SqlColumnInfo::TYPE_FLOAT,
							SQLITE3_TEXT => SqlColumnInfo::TYPE_STRING,
							SQLITE3_BLOB => SqlColumnInfo::TYPE_STRING,
							SQLITE3_NULL => SqlColumnInfo::TYPE_NULL,
						];
						$value = $row[$columnName];
						$colInfo[$i] = new SqlColumnInfo($columnName, $columnTypeMap[$result->columnType($i)]);
						if ($colInfo[$i]->getType() === SqlColumnInfo::TYPE_FLOAT) {
							if ($value === "NAN") {
								$value = NAN;
							} elseif ($value === "INF") {
								$value = INF;
							} elseif ($value === "-INF") {
								$value = -INF;
							}
						}
						$row[$columnName] = $value;
					}
					$rows[] = $row;
				}
				$ret = new SqlSelectResult($colInfo, $rows);
				$result->finalize();
				$stmt->close();
				return $ret;
		}

		throw new InvalidArgumentException("Unknown mode $mode");
	}

	protected function close(&$resource) : void
	{
		assert($resource instanceof SQLite3);
		$resource->close();
	}

	public function getThreadName() : string
	{
		return __NAMESPACE__ . " connector #$this->slaveNumber";
	}
}
