<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_migrations_run_and_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }
}
