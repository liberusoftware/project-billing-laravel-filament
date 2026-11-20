<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->previousApplication = Facade::getFacadeApplication();
    $container = new Container;
    $this->database = new Capsule($container);
    $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $container->instance('db', $this->database->getDatabaseManager());
    $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

afterEach(function () {
    $this->database->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousApplication);
});

it('creates the token schema once and retains existing credentials on repeat runs and rollback', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_06_29_124225_create_personal_access_tokens_table.php';
    $migration->up();
    expect(Schema::hasColumns('personal_access_tokens', ['token', 'abilities', 'expires_at']))->toBeTrue();
    DB::table('personal_access_tokens')->insert(['tokenable_type' => 'FixtureActor', 'tokenable_id' => 1, 'name' => 'Existing', 'token' => str_repeat('a', 64)]);
    $migration->up();
    $migration->down();
    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->value('token'))->toBe(str_repeat('a', 64));
});
