<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Role Enum
    |--------------------------------------------------------------------------
    |
    | This string-backed enum defines every role that your application grants.
    | Each case maps to the permissions it holds through its permissions()
    | method, so changing what a role means is a code change and deploy.
    |
    */

    'roles' => Role::class,

    /*
    |--------------------------------------------------------------------------
    | Permission Enum
    |--------------------------------------------------------------------------
    |
    | This string-backed enum defines every permission checked through the Gate.
    | Each case gets registered as a native Laravel ability at boot, so you
    | may use can(), @can, and the can: middleware with the enum values.
    |
    */

    'permissions' => Permission::class,

    /*
    |--------------------------------------------------------------------------
    | Super Admin Role
    |--------------------------------------------------------------------------
    |
    | Users holding this role will pass every Gate check before any other
    | ability or policy is consulted. Set this to a case of your role enum,
    | such as Role::Admin, or leave it as null to disable this shortcut.
    |
    */

    'super_admin' => null,

    /*
    |--------------------------------------------------------------------------
    | Doctrine Entity Paths
    |--------------------------------------------------------------------------
    |
    | Directories Doctrine scans for attribute-mapped entities, in addition to
    | the package's own entities. Point this at wherever your application keeps
    | its Doctrine entities.
    |
    */

    'entities' => [
        app_path('Entities'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The Laravel database connection Doctrine should use. Leave it null to use
    | your default connection from config/database.php.
    |
    */

    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Key Type
    |--------------------------------------------------------------------------
    |
    | The identifier type of your user entity, and therefore of the user_id and
    | scopeable_id columns. Use "int" for auto-incrementing keys, or "uuid" /
    | "ulid" for 36 / 26 character string keys. `grant:install --key-type` writes
    | the matching dbmate migration and this value together.
    |
    */

    'key_type' => 'int',

    /*
    |--------------------------------------------------------------------------
    | dbmate Path
    |--------------------------------------------------------------------------
    |
    | Directory `grant:install` writes the dbmate migration to, relative to the
    | application root unless an absolute path is given.
    |
    */

    'dbmate_path' => 'db/migrations',

];
