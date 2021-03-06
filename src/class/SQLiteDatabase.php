<?php

final class SQLiteDatabase extends DatabaseModel implements CustomDatabaseInterface {

    const UPPER_LIMIT = 18446744073709551615;

    private $dbms = [

        /**
         * DBMS-Specific Classes
         */
        'classes' => [
            'condition' => 'SQLiteCondition',
            'conditionGroup' => 'SQLiteConditionGroup',
            'grammar' => 'SQLiteGrammar'
        ],

        /**
         * PDO DSN Configuration
         */
        'dsn' => [
            'prefix' => 'sqlite',
            'args' => [
                [
                    'name' => null,
                    'value' => 'name',
                    'required' => true
                ]
            ]
        ],

        /**
         * SQL Statement Configuration
         */
        'sql' => [

            'columnExists' => [
                'stmt' => 'PRAGMA table_info( ? );',
                'args' => [
                    [ 'value' => 'table',   'type' => self::TYPE_STR ]
                ],
                'action' => [
                    'id' => self::ACTION_IN_ARRAY,
                    'args' => [
                        [ 'name' => 'needle',   'value' => 'column' ],
                        [ 'name' => 'haystack', 'value' => 'results' ]
                    ]
                ]
            ],

            'delete' => [
                'stmt' => 'DELETE FROM ${table} WHERE ${condition} LIMIT ?, ?;',
                'args' => [
                    [ 'value' => 'start',   'type' => self::TYPE_INT ],
                    [ 'value' => 'limit',   'type' => self::TYPE_INT ]
                ],
                'tables' => [ 'table' ]
            ],

            'getColumns' => [
                'stmt' => 'PRAGMA table_info( ? );',
                'args' => [
                    [ 'value' => 'table',   'type' => self::TYPE_STR ]
                ]
            ],

            'getTables' => [
                'stmt' => 'SELECT name FROM sqlite_master WHERE type = \'table\';',
                'args' => []
            ],

            'insert' => [
                'stmt' => 'INSERT INTO ?{table} ( ?{setcolumns} ) VALUES ( ?{set} );',
                'tables' => [ 'table' ],
                'sets' => [ 'values' ],
                'columnSets' => [ 'columns' ]
            ],

            'selectConditional' => [
                'stmt' => 'SELECT ?{setcolumns} FROM ?{table} WHERE ${conditions} LIMIT ?, ?;',
                'args' => [
                    [ 'value' => 'start',   'type' => self::TYPE_INT ],
                    [ 'value' => 'limit',   'type' => self::TYPE_INT ]
                ],
                'tables' => [ 'table' ],
                'columnSets' => [ 'columns' ],
                'conditions' => [ 'conditions' ]
            ],

            'selectUnconditional' => [
                'stmt' => 'SELECT ?{setcolumns} FROM ${table} LIMIT ?, ?;',
                'args' => [
                    [ 'value' => 'start',   'type' => self::TYPE_INT ],
                    [ 'value' => 'limit',   'type' => self::TYPE_INT ]
                ],
                'tables' => [ 'table' ],
                'columnSets' => [ 'columns' ]
            ],

            'tableExists' => [
                'stmt' => 'SELECT name FROM sqlite_master WHERE type=\'table\' AND name = ?;',
                'table' => [
                    [ 'value' => 'table',   'type' => self::TYPE_STR ]
                ]
            ]

        ]

    ];

}

?>
