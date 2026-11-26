<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the role and permission catalogue before each test that refreshes the database.
     */
    protected bool $seed = true;

    /**
     * @var class-string
     */
    protected string $seeder = RolesAndPermissionsSeeder::class;
}
