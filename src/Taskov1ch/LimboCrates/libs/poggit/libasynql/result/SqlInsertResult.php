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

namespace Taskov1ch\LimboCrates\libs\poggit\libasynql\result;

class SqlInsertResult extends SqlChangeResult
{
	/** @var int */
	private $insertId;

	public function __construct(int $affectedRows, int $insertId)
	{
		parent::__construct($affectedRows);
		$this->insertId = $insertId;
	}

	public function getInsertId() : int
	{
		return $this->insertId;
	}
}
