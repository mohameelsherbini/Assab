<?php

namespace Tests\Integration;

use Tests\Feature\Pkg67MariaDbCompositionTest;

/**
 * Oracle MySQL/InnoDB counterpart to the historical 22-scenario MariaDB suite.
 * The inherited test methods keep the scenario bodies and safety assertions identical;
 * this class has its own opt-in, server identity, version, schema and engine guards.
 * Run this file explicitly so its skipped cases do not change the default SQLite suite.
 */
class Pkg67MySqlInnoDbCompositionTest extends Pkg67MariaDbCompositionTest
{
    protected function setUp(): void
    {
        $this->setUpCompositionDatabase('mysql');
    }
}
