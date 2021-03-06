<?php

class DatabaseModel implements CustomDatabaseInterface {

    // -- PROPERTIES/MEMBERS -- //

    private $config = [];
    private $connector;
    private $dbms = [
        //Everything in here should be replaced by DBMS-specific classes, so nothing is defined
    ];
    private $encoding;
    private $host;
    private $info;
    private $name;
    private $open;
    private $port;
    private $stmtTable = [];
    private $table;

    // -- CONSTANTS/FLAGS/ENUMS -- //

    //Actions
    const ACTION_NONE           = 0;    //do nothing.
    const ACTION_HAS_ELEMENTS   = 1;    //check if data argument is an array with at least 1 element.
    const ACTION_KEY_EXISTS     = 2;    //check if key "key" exists in array "array".
    const ACTION_KEY_ISSET      = 3;    //check if key "key" is set (not null) in array "array".
    const ACTION_IN_ARRAY       = 4;    //check if "needle" is in array "haystack".

    //Fields
    const FIELD_DATA            = 0;
    const FIELD_TABLE           = 1;
    const FIELD_COLUMN          = 2;

    //Keywords
    const KEYWORD_NONE          = 0;
    const KEYWORD_ALL           = 1;

    //Extra Prepared Statement Parameter Markers
    const PARAM_BASIC           = '?$';             //string that represents a dynamically inserted basic variable parameter
    const PARAM_COLUMN          = '?{column}';      //string that represents a dynamically inserted column parameter
    const PARAM_COLUMN_SET      = '?{setcolumns}';  //string that represents a dynamically inserted set of column parameters
    const PARAM_CONDITIONS      = '?{conditions}';  //string that represents a dynamically inserted condition parameter
    const PARAM_SET             = '?{set}';         //string that represents a dynamically inserted set of literal parameters
    const PARAM_TABLE           = '?{table}';       //string that represents a dynamically inserted table parameter
    const PARAM_TABLE_SET       = '?{settables}';   //string that represents a dynamically inserted set of table parameters

    //Sort Orders
    const SORT_NONE             = 0;
    const SORT_ASC              = 1;
    const SORT_DESC             = 2;

    //Prepared Statement Input Types
    const TYPE_BOOL             = PDO::PARAM_BOOL;          //boolean data type
    const TYPE_INT              = PDO::PARAM_INT;           //integer data type
    const TYPE_LOB              = PDO::PARAM_LOB;           //large object data type
    const TYPE_NULL             = PDO::PARAM_NULL;          //null data type
    const TYPE_OUTPUT           = PDO::PARAM_INPUT_OUTPUT;  //INOUT parameter for stored procedure (must be bitwise-OR'd with another data type)
    const TYPE_STMT             = PDO::PARAM_STMT;          //recordset type (not supported at the moment)
    const TYPE_STR              = PDO::PARAM_STR;           //string data type

    //Random Informational Constants
    const DEFAULT_OFFSET        = 0;                    //This is the default offset value. This should always be 0, but in case that's not true, it's a constant.
    const UPPER_LIMIT           = 18446744073709551615; //This is 2^64-1, the maximum 64-bit unsigned integer. This is the most common upper limit for DBMS, but it could be different.

    /**
     * Constructor Method
     * @param int $type (required) -
     * @param string $name (required) -
     * @param string $user (required for secured) -
     * @param string $pass (required for secured) -
     * @param string $host (required for remote, optional because it defaults to localhost) -
     * @param integer $port (required for remote, optional because it defaults to server type's default port) -
     * @param strign $table (optional) -
     */
    public function __construct (
        $name,
        $user   = null,
        $pass   = null,
        $host   = 'localhost',
        $port   = null,
        $table  = null
    ) {

        $data = []; //instantiate data array

        /*
         * Input Handling for $name (database name)
         *
         * $name is required, must be a string, and must be a valid database name,
         * but we cannot check if it's a valid name until we connect.
         */
        if (isset($name)) {
            //database name is set
            if (gettype($name) != 'string') {
                //database name is not a string
                throw new DatabaseException(
                    __METHOD__.'(): encountered database name argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            } else {
                $data['name'] = $name; //add validated database name to data array
            }
        } else {
            //database name is not set
            throw new DatabaseException(
                __METHOD__.'(): missing required database name argument.',
                DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                $this
            );
        }

        /*
         * Input Handling for $user (username)
         *
         * $user is only required for servers that require authentication. We cannot
         * test if authentication is required until after connecting. $user also must
         * be a string.
         */
        if ($user !== null) {
            //username is set
            if (gettype($user) != 'string') {
                //username is not a string
                throw new DatabaseException(
                    __METHOD__.'(): encountered username argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            } else {
                $data['user'] = $user; //add validated username to data array
            }
        }

        /*
         * Input Handling for $pass (password)
         *
         * $pass is only required for servers that require authentication. We cannot
         * test if authentication is required until after connecting. $pass also must
         * be a string.
         */
        if ($pass !== null) {
            //password is set
            if (gettype($pass) != 'string') {
                //password is not a string
                throw new DatabaseException(
                    __METHOD__.'(): encountered password argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            } else {
                $data['pass'] = $pass; //add validated password to data array
            }
        }

        /*
         * Input Handling for $host (server hostname)
         *
         * $host is only required for remote database servers (so not sqlite). However,
         * $host already has a default value of 'localhost', which means that it doesn't
         * have to be provided in an argument if using localhost. $host must be a string,
         * $host must be a valid IP address, hostname, or domain name, and if there is a
         * port after a : in the URL, that must be separated into $port.
         */
        if ($host != 'localhost') {
            //hostname is set
            if (gettype($host) == 'string') {
                //hostname is a string
                if (!DatabaseUtils::isValidHost($host)) {
                    //hostname has invalid syntax
                    throw new DatabaseException(
                        __METHOD__.'(): encountered invalid given hostname (do not include URI scheme, port numbers, or paths!).',
                        DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                        $this
                    );
                } else {
                    $data['host'] = $host; //add validated hostname to data array
                }
            } else {
                //hostname is not a string
                throw new DatabaseException(
                    __METHOD__.'(): encountered hostname argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            $data['host'] = $host; //add default hostname to data array
        }

        /*
         * Input Handling for $port (server port number)
         *
         * $port is only required for remote database servers (so not sqlite). However,
         * $port will be given a default value based on the server type. $port must be an
         * integer greater than or equal to 0 and less than or equal to 65535. The actual
         * validity of the port cannot be validated until trying to connect to the server.
         */
        if ($port !== null) {
            //port is set
            if (!is_int($port)) {
                //port is not an integer
                if (is_numeric($port)) {
                    $port = (integer)$port;
                    if (0 > $port || $port > 65535) {
                        throw new DatabaseException(
                            __METHOD__.'(): encountered port number argument outside of legal bounds.',
                            DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                            $this
                        );
                    } else {
                        $data['port'] = $port; //add validated password to data array
                    }
                } else {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered port number argument of invalid type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
            }
        } else {
            //port is not set
            $port = isset($this->dbms['config']['defaultPort']) ?
                $this->dbms['config']['defaultPort'] : //get default port
                null; //no port available
            $data['port'] = $port; //add validated port number to data array
        }

        /*
         * Input Handling for $table (default table)
         *
         * $table is optional. This only has to be set when a default table is required.
         * If it is not set, other methods will need to be given a table argument or they will
         * error. Table must be a string, and must be a valid name for a SQL table. We won't know
         * if the default table is valid until after the database connection is established, but
         * it will be tested then.
         */
        if ($table !== null) {
            //table is set
            if (gettype($table) != 'string') {
                //table is not a string
                throw new DatabaseException(
                    __METHOD__.'(): encountered table argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            } else {
                $data['table'] = $table;
            }
        } else {
            $data['table'] = $table;
        }

        //Set class properties/members
        $this->name     = $data['name'];
        $this->user     = $data['user'];
        $this->pass     = $data['pass'];
        $this->host     = $data['host'];
        $this->port     = $data['port'];
        $this->table    = $data['table'];

        //generate DSN
        $dsn = $this->dbms['dsn']['prefix'].':'; //add prefix
        $dsnargs = []; //instantiate dsn arguments array
        foreach ($this->dbms['dsn']['args'] as $arg) { //loop over each argument
            if ($arg['required']) { //check if argument is required
                if (!isset($data[$arg['value']]) || $data[$arg['value']] == null) { //check if required argument is missing
                    throw new DatabaseException(
                        __METHOD__.'(): missing required argument "'.$arg['value'].'" to build DSN.'.json_encode($data),
                        DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                        $this
                    );
                    continue; //skip iteration (in case exception is caught)
                }
            }

            //create DSN argument and insert it into argument array
            if (isset($arg['name']) && $arg['name'] != null) { //if argument has a name...
                $dsnargs[] = $arg['name'].'='.str_replace(';', '', $data[$arg['value']]); //format like "name=value"...
            } else { //if argument has no name...
                $dsnargs[] = str_replace(';', '', $data[$arg['value']]); //format like "value".
            }
        }
        $dsn .= implode(';', $dsnargs); //combine all arguments, separate with ';', add to DSN

        //create PDO object with DSN (this is the actual connection)
        try {
            $this->connector = new PDO($dsn, $user, $pass);
            $this->connector->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connector->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            throw new DatabaseException(
                __METHOD__.'(): caught exception when opening the database connection',
                DatabaseException::EXCEPTION_GENERIC_DATABASE_ERROR,
                $this,
                $e
            );
        }

    }

    /**
     * Invocation Method ( $DatabaseModelInstance() )
     */
    public function __invoke () {

        return $this->open;

    }

    /**
     * String Conversion Method ( (string) $DatabaseModelInstance )
     */
    public function __toString () {

        return serialize($this);

    }

    /**
     * DatabaseModel->columnConform() Method
     *
     * Alter column to conform with the given schema.
     *
     * @param  string $table     Name of the table to alter.
     * @param  string $column    Name of the column to alter.
     * @return bool              True on success, false on failure.
     * @throws DatabaseException If someone dun goofed.
     */
    public function columnConform ($column, $table = null) {

        if ($this->hasSchema()) {
            if (!$this->columnMatches($column, $table)) {
                //TODO: Implement alter column to match the schema in the object.
            } else {
                return true; //schema already conforms
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): No schema available.',
                DatabaseException::EXCEPTION_MISSING_DEFINITION,
                $this
            );
            return false;
        }

    }

    /**
     * DatabaseModel->columnExists() Method
     *
     * Check if a given column exists.
     *
     * @param  string $column    Name of the column for which to check.
     * @param  string $table     Name of the table in which to check (optional if default table).
     * @return bool              True if exists, false if not.
     * @throws DatabaseException If someone dun goofed.
     */
    public function columnExists ($column, $table = null) {

        if ($table == null) {
            if ($this->hasDefaultTable()) {
                $table = $this->table;
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): missing required table definition or argument.',
                    DatabaseException::EXCEPTION_MISSING_DEFINITION,
                    $this
                );
            }
        } else {
            if (!$this->tableExists($table)) {
                throw new DatabaseException(
                    __METHOD__.'(): table provided in argument does not exist.',
                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                    $this
                );
                //just in case someone decides to be all dangerous and catch that exception.
                return false; //obviously it doesn't exist, neither does the table.
            }
        }

        $vars['column'] = $column; //add column to config-accessible vars
        $vars['table'] = $table; //add table to config-accessible vars

        $query = $this->dbms['sql']['columnExists']['stmt'];
        $query = $this->genStmt(
            $query,
            isset($this->dbms['sql']['columnExists']['tables']) ?   //if tables array given...
                $this->dbms['sql']['columnExists']['tables'] :      //use it...
                [],                                                 //otherwise use empty array.
            isset($this->dbms['sql']['columnExists']['columns']) ?      //if columns array given...
                $this->dbms['sql']['columnExists']['columns'] :
                [],
            isset($this->dbms['sql']['columnExists']['sets']) ?         //if sets array given...
                $this->dbms['sql']['columnExists']['sets'] :
                [],
            isset($this->dbms['sql']['columnExists']['tableSets']) ?    //if table sets array given...
                $this->dbms['sql']['columnExists']['tableSets'] :
                [],
            isset($this->dbms['sql']['columnExists']['columnSets']) ?   //if column sets array given...
                $this->dbms['sql']['columnExists']['columnSets'] :
                [],
            isset($this->dbms['sql']['columnExists']['conditions']) ?   //if conditions array given...
                $this->dbms['sql']['columnExists']['conditions'] :
                [],
            $table
        );
        $stmt = $this->connector->prepare($query);
        $i = 1;
        foreach ($this->dbms['sql']['columnExists']['args'] as $arg) {
            $stmt->bindParam($i, $data[$arg['value']], PDO::PARAM_STR);
            $i++;
        }
        $stmt->execute();
        $var['results'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        //if action is defined
        if (isset($this->dbms['sql']['columnExists']['action'])) {
            $data = []; //instantiate data array
            //if action has arguments
            if (isset($this->dbms['sql']['columnsExists']['action']['args'])) {
                //add each argument to the data array
                foreach ($this->dbms['sql']['columnExists']['action']['args'] as $arg) {
                    $data[$arg['name']] = $vars[$arg['value']]; //
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): Action given no variables.',
                    DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                    $this
                );
            }
            //if action has an id
            if (isset($this->dbms['sql']['columnExists']['action']['id'])) {
                return $this->doAction($this->dbms['sql']['columnExists']['action']['id'], $data);
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): Action given no ID.',
                    DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                    $this
                );
            }
        } else {
            //default action
            $data = [
                'needle' => $var['column'],
                'haystack' => $var['results']
            ];
            return $this->doAction(self::ACTION_HAS_ELEMENTS, $data);
        }

    }

    /**
     * DatabaseModel->columnMatches() Method
     *
     * Check if column matches the given schema.
     *
     * @param  string $column    Name of the column to check.
     * @param  string $table     Name of the table in which to check.
     * @return bool              True if it matches, false if not.
     * @throws DatabaseException If someone dun goofed.
     */
    public function columnMatches ($column, $table = null) {

        //TODO: Implement check if schema for given column in object matches actual schema.

    }

    /**
     * DatabaseModel->delete() Method
     *
     * Deletes rows matching given condition array
     *
     * @param  array  $condition (required) - conditioon array passed to DBMS condition object
     * @param  int    $start     (optional) - offset at which to start deleting
     * @param  int    $limit     (optional) - limit number of rows to affect
     * @param  string $table     (optional) - name of table, not needed if table is predefined
     * @return bool              returns true on success, false on failure
     * @throws DatabaseException if someone dun goofed
     */
    public function delete ($condition, $start = null, $limit = null, $table = null) {

        //TODO: Implement 'delete' functionality

    }

    /**
     * DatabaseModel->doAction() private Method
     *
     * Perform a predefined callback-style action on a given data set. If you
     * use this, take note of the referenced keys for the given action. The
     * intended purpose of this method was to provide a way for alternative
     * final data processing to be configured per-DBMS without having to
     * entirely rewrite methods.
     *
     * @param  int     $id       The ID of the action to use. Recommend using the constants for this.
     * @param  unknown $data     The ambigous block of data. Usually this will be an array, especailly when multiple values are used.
     * @return unknown           The output of the action. This could be just about anything.
     * @throws DatabaseException If someone dun goofed.
     */
    private function doAction ($id, $data) {

        switch ($id) {
            case self::ACTION_NONE:
                return $data;
                break;
            case self::ACTION_HAS_ELEMENTS:
                return count($data) > 0;
                break;
            case self::ACTION_KEY_EXISTS:
                return array_key_exists($data['key'], $data['array']);
                break;
            case self::ACTION_KEY_ISSET:
                return isset($data['array'][$data['key']]);
                break;
            case self::ACTION_IN_ARRAY:
                return in_array($data['needle'], $data['haystack']);
                break;
            default:
                throw new DatabaseException(
                    __METHOD__.'(): Unknown action id called.',
                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                    $this
                );
                break;
        }

    }

    /**
     * DatabaseException->exec() Method
     *
     * Prepare and execute a statement. This method is recommended for single-
     * use queries. It is optimized by checking a table of MD5 hashes of
     * existing prepared statements. Regardless of this optimization, for any
     * queries that will be run multiple times, it is strongly recommended that
     * you use the DatabaseStatement->exec() method. Using that method, there
     * will need to be no MD5 checksums, hash table checks, string comparisons,
     * or compilation. This is provided only for convenience.
     *
     * @param  string $query      The statement to prepare for execution
     * @param  array  $args       Array of arguments to the given statement
     * @param  array  $tables     Array of table values to insert
     * @param  array  $columns    Array of column values to insert
     * @param  array  $sets       Array of set values to insert
     * @param  array  $tablesets  Array of table set values to insert.
     * @param  array  $columnsets Array of column set values to insert.
     * @param  array  $conditions Array of DatabaseCondition objects to insert.
     * @param  int    $action     ID of action to execute on final data. Recommend using constants for this.
     * @param  array  $actionargs Array representing data to give to action using $val keys.
     * @return array              Array of data returned from the execution.
     * @throws DatabaseException  If someone dun goofed.
     */
    public function exec (
        $query,
        $args       = [],
        $tables     = [],
        $columns    = [],
        $sets       = [],
        $tablesets  = [],
        $columnsets = [],
        $conditions = [],
        $action     = self::ACTION_NONE,
        $actionargs = []
    ) {

        if (empty($query)) {
            throw new DatabaseException(
                __METHOD__.'(): missing required query argument.',
                DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($args)) {
            throw new DatabaseException(
                __METHOD__.'(): args argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($tables)) {
            throw new DatabaseException(
                __METHOD__.'(): tables argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($columns)) {
            throw new DatabaseException(
                __METHOD__.'(): columns argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($sets)) {
            throw new DatabaseException(
                __METHOD__.'(): sets argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($tablesets)) {
            throw new DatabaseException(
                __METHOD__.'(): tablesets argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!is_array($columnsets)) {
            throw new DatabaseException(
                __METHOD__.'(): columnsets argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }
        if (
            !is_array($conditions) &&
            !(
                is_object($conditions) &&
                is_subclass_of($conditions, $this->dbms['classes']['condition'])
            )
        ) {
            throw new DatabaseException(
                __METHOD__.'(): conditions argument must be an array or an object descended from class '.$this->dbms['classes']['condition'].'.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }
        if (!is_int($action)) {
            throw new DatabaseException(
                __METHOD__.'(): action argument must be an integer (see '.__CLASS__.'::ACTION_* constants).',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }
        if (!is_array($actionargs)) {
            throw new DatabaseException(
                __METHOD__.'(): action arguments argument must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }

        $grammar = [ //this thing is just temporary I think
            'stmt' => $query,
            'args' => $args,
            'tables' => $tables,
            'columns' => $columns,
            'sets' => $sets,
            'tablesets' => $tablesets,
            'columnsets' => $columnsets,
            'conditions' => $conditions,
            'action' => [
                'id' => $action,
                'args' => $actionargs
            ]
        ];

        //TODO: Implement this function as a more end-user-friendly wrapper around DatabaseModel->runQuery();

    }

    /**
     * DatabaseModel->genStmt private Method
     *
     * Create a dynamically generated preparable statement. This allows emulated
     * parameterized identifiers, sets, and conditions. Identifiers are santized
     * by checking if they exist in the actual context. Sets are santized as
     * normal prepared statement parameters. Conditions are sanitized by being
     * generated with DatabaseCondition class from an array structure only.
     *
     * @param  string $stmt       Unpreparable statement string with extra parameter placeholders.
     * @param  array  $tables     Array of table values to insert.
     * @param  array  $columns    Array of column values to insert.
     * @param  array  $sets       Array of set values to insert.
     * @param  array  $tablesets  Array of table set values to insert.
     * @param  array  $columnsets Array of column set values to insert.
     * @param  array  $conditions Array of DatabaseCondition objects to insert.
     * @param  string $table      (optional if defined in constructor) Default context table.
     * @return string             Preparable statement. (Note that values of sets will still need to be given at execution time)
     * @throws DatabaseException  If someone dun goofed.
     */
    private function genStmt (
        $stmt,
        $tables     = [],
        $columns    = [],
        $sets       = [],
        $tablesets  = [],
        $columnsets = [],
        $conditions = [],
        $table      = null
    ) {

        if (isset($stmt)) {

            //validate types
            if (
                is_string($stmt)        &&
                is_array($tables)       &&
                is_array($columns)      &&
                is_array($sets)         &&
                is_array($tablesets)    &&
                is_array($columnsets)   &&
                is_array($conditions)   &&
                (is_string($table) || $table == null)
            ) {
                $validTables = $this->getTables();
                $columnsTable = [];

                // -- TABLES -- //
                foreach ($tables as $table) {
                    if (in_array($table, $validTables)) {

                    } else {
                        throw new DatabaseException(
                            __METHOD__.'(): table "'.$table.'" does not exist.',
                            DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                            $this
                        );
                        $stmt = DatabaseUtils::replaceOnce(self::PARAM_TABLE, '', $stmt); //delete this placeholder (in case exception is caught)
                        array_shift($table); //shift out the table (in case exception is caught)
                    }
                }

                // -- COLUMNS -- //
                foreach ($columns as $column) {

                    $typeof_column = gettype($column);

                    if (isset($this->table)) {
                        if (!in_array($this->table, $validTables)) {
                            throw new DatabaseException(
                                __METHOD__.'(): default table "'.$this->table.'" does not exist.',
                                DatabaseException::EXCEPTION_CORRUPTED_OBJECT,
                                $this
                            );
                        }
                    }
                    $columnsTable[$this->table] = $this->getColumns($this->table);

                    if ($typeof_column == 'string') {
                        //TODO: check $table, then $this->table, then exception. do the replacement if any are found.
                        if (
                            (!isset($table) || $table == null) &&
                            (isset($this->table) && $this->table != null)
                        ) {
                            $table = $this->table;
                        } else {
                            throw new DatabaseException(
                                __METHOD__.'(): no table defined.',
                                DatabaseException::EXCEPTION_MISSING_DEFINITION,
                                $this
                            );
                            continue; //skip this iteration (in case exception was caught)
                        }
                        //TODO: Do replacement here
                    } elseif ($typeof_column == 'array') {
                        if (array_key_exists('table', $column) && array_key_exists('column', $column)) {
                            if (!array_key_exists($column['table'], $columnsTable)) {
                                $columnsTable[$column['table']] = $this->getColumns($column['table']);
                            }
                            if (in_array($column['column'], $columnTable[$column['table']])) {
                                $stmt = DatabaseUtils::replaceOnce(self::PARAM_COLUMN, $this->quoteColumn($column['column']), $stmt);
                            } else {
                                throw new DatabaseException(
                                    __METHOD__.'(): column "'.$column['column'].'" does not exist in table "'.$column['table'].'".',
                                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                                    $this
                                );
                            }
                        } else {
                            throw new DatabaseException(
                                __METHOD__.'(): encountered invalid [\'table\',\'column\'] array structure.',
                                DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                                $this
                            );
                            $stmt = DatabaseUtils::replaceOnce(self::PARAM_COLUMN, '', $stmt); //delete this placeholder (in case exception is caught)
                            array_shift($table); //shift out the table (in case exception is caught)
                        }
                    } else {
                        throw new DatabaseException(
                            __METHOD__.'(): encountered column parameter of invalid type.',
                            DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                            $this
                        );
                    }
                }

                // -- SETS -- //
                foreach ($sets as $set) {
                    $typeof_set = gettype($set);
                    $count = count($set);
                    $pseudoArray = [];
                    for ($i = 0; $i < $count; $i++) {
                        $pseudoArray[] = '?';
                    }
                    $pseudoSet = implode(',', $pseudoArray);
                    $stmt = DatabaseUtils::replaceOnce(self::PARAM_SET, $pseudoSet, $stmt);
                    //NOTE: This will only create a preparable statement.
                    //You'll still have to bind the parameters for basic sets.
                }

                // -- TABLE SETS -- //
                foreach ($tablesets as $set) {
                    $typeof_set = gettype($set);

                    if (in_array($table, $validTables)) {

                    } else {
                        throw new DatabaseException(
                            __METHOD__.'(): table "'.$table.'" does not exist.',
                            DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                            $this
                        );
                        $stmt = DatabaseUtils::replaceOnce(self::PARAM_TABLE, '', $stmt); //delete this placeholder (in case exception is caught)
                        array_shift($table); //shift out the table (in case exception is caught)
                    }
                }

                // -- COLUMN SETS -- //
                foreach ($columnsets as $set) {
                    $typeof_set = gettype($set);

                    if (isset($this->table)) {
                        if (!in_array($this->table, $validTables)) {
                            throw new DatabaseException(
                                __METHOD__.'(): default table "'.$this->table.'" does not exist.',
                                DatabaseException::EXCEPTION_CORRUPTED_OBJECT,
                                $this
                            );
                        }
                    }
                    $columnsTable[$this->table] = $this->getColumns($this->table);

                    if ($typeof_column == 'string') {
                        //TODO: check $table, then $this->table, then exception. do the replacement if any are found.
                        if (
                            (!isset($table) || $table == null) &&
                            (isset($this->table) && $this->table != null)
                        ) {
                            $table = $this->table;
                        } else {
                            throw new DatabaseException(
                                __METHOD__.'(): no table defined.',
                                DatabaseException::EXCEPTION_MISSING_DEFINITION,
                                $this
                            );
                            continue; //skip this iteration (in case exception was caught)
                        }
                        //TODO: Do replacement here
                    } elseif ($typeof_column == 'array') {
                        if (array_key_exists('table', $column) && array_key_exists('column', $column)) {
                            if (!array_key_exists($column['table'], $columnsTable)) {
                                $columnsTable[$column['table']] = $this->getColumns($column['table']);
                            }
                            if (in_array($column['column'], $columnTable[$column['table']])) {
                                $stmt = DatabaseUtils::replaceOnce(self::PARAM_COLUMN, $this->quoteColumn($column['column']), $stmt);
                            } else {
                                throw new DatabaseException(
                                    __METHOD__.'(): column "'.$column['column'].'" does not exist in table "'.$column['table'].'".',
                                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                                    $this
                                );
                                continue; //skip this iteration (if exception is caught)
                            }
                        } else {
                            throw new DatabaseException(
                                __METHOD__.'(): encountered invalid [\'table\',\'column\'] array structure.',
                                DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                                $this
                            );
                            $stmt = DatabaseUtils::replaceOnce(self::PARAM_COLUMN, '', $stmt); //delete this placeholder (in case exception is caught)
                            continue; //skip this iteration (if exception is caught)
                        }
                    } else {
                        throw new DatabaseException(
                            __METHOD__.'(): encountered column parameter of invalid type.',
                            DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                            $this
                        );
                        continue; //skip this iteration (if exception is caught)
                    }
                }

                // -- CONDITIONS -- //
                foreach ($conditions as $condition) {
                    $typeof_condition = gettype($condition);

                    if ($typeof_condition == 'object') {
                        if (is_subclass_of($condition, 'DatabaseConditionModel')) {
                            $strCond = $condition->getStatement();
                            $stmt = DatabaseUtils::replaceOnce(self::PARAM_CONDITION, $strCond, $stmt);
                        } else {
                            throw new DatabaseException(
                                __METHOD__.'(): encountered condition parameter not descended from DatabaseConditionModel.',
                                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                                $this
                            );
                            $stmt = DatabaseUtils::replaceOnce(self::PARAM_CONDITION, '', $stmt);
                            continue; //skip this iteration (in case exception is caught)
                        }
                    } else {
                        throw new DatabaseException(
                            __METHOD__.'(): encountered condition parameter of invalid type.',
                            DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                            $this
                        );
                    }
                }

            } else {
                //$stmt type violation
                if (!is_string($stmt)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered statement of non-string type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$tables type violation
                if (!is_array($tables)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered table array of non-array type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$columns type violation
                if (!is_array($columns)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered column array of non-array type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$sets type violation
                if (!is_array($sets)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered set array of non-array type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$tablesets type violation
                if (!is_array($tablesets)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered table-set array of non-array type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$columnsets type violation
                if (!is_array($columnsets)) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered column-set array of non-array type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
                //$table type violation
                if (!is_string($table) && $table != null) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered table argument of invalid type.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): missing required statement string argument.',
                DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                $this
            );
        }

        return $stmt;

    }

    /**
     * DatabaseModel->getAllSchemas() Method
     *
     * Get an array of all schemas available to the database.
     *
     * @return array Array of schemas available to the database, sorted by identity/versioning info.
     */
    public function getAllSchemas () {

        return $this->schemas;

    }

    /**
     * DatabaseModel->getColumns() Method
     *
     * Get an array of existing columns in a given (or default) table.
     *
     * @param  string $table     (optional) Name of table, unless table defined by constructor
     * @return array             Array of columns in the table.
     * @throws DatabaseException If someone dun goofed.
     */
    public function getColumns ($table = null) {

        if ($table == null) {
            if ($this->table != null) {
                $table = $this->table;
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): missing table setting and/or table argument.',
                    DatabaseException::EXCEPTION_MISSING_DEFINITION,
                    $this
                );
            }
        }

        //TODO: this is still not complete yet.

        $query = $this->dbms['sql']['getColumns']['stmt'];
        $query = $this->genStmt(
            $query,
            (isset($this->dbms['sql']['getColumns']['tables']) ?     //if tables array given...
                $this->dbms['sql']['getColumns']['tables'] :        //use it...
                []),                                                 //otherwise use empty array.
            (isset($this->dbms['sql']['getColumns']['columns']) ?        //if columns array given...
                $this->dbms['sql']['getColumns']['columns'] :
                []),
            (isset($this->dbms['sql']['getColumns']['sets']) ?           //if sets array given...
                $this->dbms['sql']['getColumns']['sets'] :
                []),
            (isset($this->dbms['sql']['getColumns']['tableSets']) ?      //if table sets array given...
                $this->dbms['sql']['getColumns']['tableSets'] :
                []),
            (isset($this->dbms['sql']['getColumns']['columnSets']) ?     //if column sets array given...
                $this->dbms['sql']['getColumns']['columnSets'] :
                []),
            (isset($this->dbms['sql']['getColumns']['conditions']) ?     //if conditions array given...
                $this->dbms['sql']['getColumns']['conditions'] :
                []),
            $table
        );
        //DEBUG error_log($query);
        $stmt = $this->connector->prepare($query);
        $i = 1;
        foreach ($this->dbms['sql']['getColumns']['args'] as $arg) {
            if (array_key_exists('value', $arg)) {
                $stmt->bindParam(
                    $i,
                    $data[$arg['value']],
                    array_key_exists('type', $arg) ? $arg['type'] : self::TYPE_STR
                );
                $i++;
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered parameter without value key.',
                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                    $this
                );
                continue; //skip this iteration (in case that exception was caught)
            }
        }
        $stmt->execute();
        $var['results'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        //if action is defined
        if (isset($this->dbms['sql']['getColumns']['action'])) {
            $data = []; //instantiate data array
            //if action has arguments
            if (isset($this->dbms['sql']['getColumns']['action']['args'])) {
                //add each argument to the data array
                foreach ($this->dbms['sql']['getColumns']['action']['args'] as $arg) {
                    $data[$arg['name']] = $vars[$arg['value']]; //
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): Action given no variables.',
                    DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                    $this
                );
            }
            //if action has an id
            if (isset($this->dbms['sql']['getColumns']['action']['id'])) {
                return $this->doAction($this->dbms['sql']['getColumns']['action']['id'], $data);
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): Action given no ID.',
                    DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                    $this
                );
            }
        } else {
            //default action
            $data = $var['results'];
            return $this->doAction(self::ACTION_NONE, $data);
        }

    }

    /**
     * DatabaseModel->getConnector() Method
     *
     * Get the database connection object (a PDO object).
     *
     * @return PDO Database connection object.
     */
    public function getConnector () {

        return $this->connector;

    }

    /**
     * DatabaseModel->getCreateQuery() Method
     *
     * Get the query that would create the current database schema.
     *
     * @return string Query to generate current schema.
     */
    public function getCreateQuery () {

        //TODO: Implement query string generator for schemas.

    }

    /**
     * DatabaseModel->getDatabaseName() method
     *
     * Get the name or filename of the connected database.
     *
     * @return string Name or filename of the database.
     */
    public function getDatabaseName () {

        return $this->name;

    }

    /**
     * DatabaseModel->getDefaultTable() Method
     *
     * Get the default table (if defined) used in this database connection.
     *
     * @return string|null Default table, as defined, or null if undefined.
     */
    public function getDefaultTable () {

        return $this->table;

    }

    /**
     * DatabaseModel->getHostname() Method
     *
     * Get the hostname of the database server, if available, or null if not.
     *
     * @return string|null Hostname of the database server, or null if undefined.
     */
    public function getHostname () {

        return $this->host;

    }

    /**
     * DatabaseModel->getPortNumber() Method
     *
     * Get the TCP/IP port number of the database server, if available, or null if not.
     *
     * @return integer|null Port number of the database server, or null if undefined.
     */
    public function getPortNumber () {

        return $this->port;

    }

    public function getSchema ($name) {

        //TODO: Implement schema lookup function.

    }

    /**
     * DatabaseModel->getTables() Method
     *
     * Get an array of existing tables in the database.
     *
     * @return array             Array of existing tablese in the database.
     * @throws DatabaseException If someone dun goofed.
     */
    public function getTables () {

        $stmt = $this->connector->prepare(
            $this->dbms['sql']['getTables']['stmt']
        );
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $results;

    }

    private function getToken ($key) {

        return DatabaseGrammarModel::getToken($key);

    }

    /**
     * DatabaseModel->handleValueIn() Method
     *
     * Handle data being input to the DBMS with DBMS feature emulation.
     *
     * @param  [type] $table [description]
     * @param  [type] $key   [description]
     * @param  [type] $value [description]
     * @return [type]        [description]
     */
    private function handleValueIn ($table, $key, $value) {

        //TODO: Implement DBMS input feature emulation.

    }

    /**
     * DatabaseModel->handleValueOut() Method
     *
     * Handle data being output from the DBMS with DBMS feature emulation.
     *
     * @param  [type] $table [description]
     * @param  [type] $key   [description]
     * @param  [type] $value [description]
     * @return [type]        [description]
     */
    private function handleValueOut ($table, $key, $value) {

        //TODO: Implement DBMS output feature emulation.

    }

    /**
     * DatabaseModel->hasDefaultTable() Method
     *
     * Check if this object has a defined default table.
     *
     * @return boolean True if $this has a default table, false otherwise.
     */
    public function hasDefaultTable () {

        if (isset($this->table) && $this->table != null) {
            return true;
        } else {
            return false;
        }

    }

    /**
     * DatabaseModel->insert() Method
     *
     * Insert data into the database.
     *
     * @param  array    $in    (required) Associative array of input to be inserted, keys being the name of columns
     * @param  string   $table (optional if defined in constructor) Table to use
     * @return Database        reference to self
     */
    public function insert ($in, $table = null) {

        $typeof_table = gettype($table);
        if ($table != null) {
            if ($typeof_table != 'string') {
                throw new DatabaseException(
                    __METHOD__.'(): encountered table argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            } else {
                if (!$this->tableExists($table)) {
                    throw new DatabaseException(
                        __METHOD__.'(): table "'.$table.'" does not exist.',
                        DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                        $this
                    );
                }
            }
        }
        if (!$this->tableExists($table)) {
            $typeof_in = gettype($in);
        }
        if ($typeof_in == 'array') {
            //input is an array

            $sql = $this->dbms['sql']['insert'];

            //TODO: Generate data array so we can dynamically process input data.

            //generate the statement
            $query = self::genStmt(
                $this->dbms['sql']['insert']['stmt'],
                ( isset($sql['tables'])     ? $data[$sql['tables']['value']]    : null ),
                ( isset($sql['columns'])    ? $data[$sql['columns']['value']]   : null ),
                ( isset($sql['sets'])       ? $data[$sql['sets']['value']]      : null ),
                ( isset($sql['tablesets'])  ? $data[$sql['tablesets']['value']] : null ),
                ( isset($sql['columnsets']) ? $data[$sql['columnsets']['value']]: null ),
                ( isset($sql['conditions']) ? $data[$sql['conditions']['value']]: null ),
                $table
            );

            //prepare the statement
            $statement = $this->connector->prepare($query);

            //execute the statement
            $depth = DatabaseUtils::arrayMaxDepth($in);
            if ($depth == 1) {
                //array depth 1 - single row insert
                $executionArray = array($table);
                foreach ($in as $inKey => $inVal) {
                    $executionArray[] = (string)$inKey;
                }
                foreach ($in as $inKey => $inVal) {
                    $executionArray[] = (string)$inVal;
                }
                try {
                    $statement->execute($executionArray);
                } catch (PDOException $e) {
                    throw new DatabaseException(
                        __METHOD__.'(): caught exception thrown by PDO.',
                        DatabaseException::EXCEPTION_GENERIC_DATABASE_ERROR,
                        $this,
                        $e
                    );
                }
            } elseif ($depth == 2) {
                //array depth 2 - multi-row insert
                $status = array();
                foreach ($in as $inVal) {
                    $executionArray = array($table);
                    foreach ($inVal as $inValKey => $inValVal) {
                        $executionArray[] = (string)$inValKey;
                    }
                    foreach ($inVal as $inValKey => $inValVal) {
                        $executionArray[] = (string)$inValVal;
                    }
                    try {
                        $statement->execute($executionArray);
                    } catch (PDOException $e) {
                        throw new DatabaseException(
                            __METHOD__.'(): caught exception thrown by PDO.',
                            DatabaseException::EXCEPTION_GENERIC_DATABASE_ERROR,
                            $this,
                            $e
                        );
                    }
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered array of excessive depth. Max depth = 2.',
                    DatabaseException::EXCEPTION_INPUT_ARRAY_TOO_DEEP,
                    $this
                );
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): encountered input of invalid type. Must be an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }

        return $this; //for method chaining (since this is a mutator)

    }

    /**
     * DatabaseModel->quoteColumn() Method
     *
     * Quote column identifiers for dynamically generated statements.
     * NOTE: THIS DOES NOT ESCAPE DATA! It is only meant as a precaution against
     * unusual characters causing errors in statement execution.
     * DEVELOPERS: Please make sure all data using this is also checked against
     * an array of known valid columns in the table. Never require the end-user
     * to do escaping themselves.
     *
     * @param  string            $string The column name to quote
     * @return string                    The quoted version of the input string
     * @throws DatabaseException         If someone dun goofed.
     */
    private function quoteColumn ($string) {

        if (isset($string)) {
            if (is_string($string)) {
                $string = str_replace('`', '``', $string);
                return '`'.$string.'`';
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): given argument was not of type string.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): missing required argument.',
                DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                $this
            );
        }

    }

    /**
     * DatabaseModel->quoteTable() Method
     *
     * Quote table identifiers for dynamically generated statements.
     * NOTE: THIS DOES NOT ESCAPE DATA! It is only meant as a precaution against
     * unusual characters causing errors in statement execution.
     * DEVELOPERS: Please make sure all data using this is also checked against
     * an array of known valid tables in the database. Never require the
     * end-user to do escaping themselves.
     *
     * @param  string            $string The table name to quote
     * @return string                    The quoted version of the input string
     * @throws DatabaseException         If someone dun goofed.
     */
    private function quoteTable ($string) {

        if (isset($string)) {
            if (is_string($string)) {
                $string = str_replace('`', '``', $string);
                return '`'.$string.'`';
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): given argument was not of type string.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): missing required argument.',
                DatabaseException::EXCEPTION_MISSING_REQUIRED_ARGUMENT,
                $this
            );
        }

    }

    /**
     * DatabaseModel->runQuery() Method
     *
     * Takes a DBMS grammar table sql array and some input data, and runs the query.
     * This is meant to be the main worker method to execute a query. Because using
     * this function depends on very strict implementation of PHPDAL data structures,
     * lest it fail completely, this is NOT a public method. If you want to run an
     * arbitrary query, you are looking for DatabaseModel->exec().
     *
     * @param  array $sql     DBMS grammar table array structure.
     * @param  array $extData External data to be passed in.
     * @return mixed          Final output of query and action function.
     */
    private function runQuery ($sql, $extData) {

        //Input validation
        if (!is_array($sql)) {
            throw new DatabaseException(
                __METHOD__.'(): SQL array argument was not an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }
        if (!isset($sql['stmt'])) {
            throw new DatabaseException(
                __METHOD__.'(): SQL array is missing stmt parameter.',
                DatabaseException::EXCEPTION_MISSING_DEFINITION,
                $this
            );
        }
        if (!is_array($data)) {
            throw new DatabaseException(
                __METHOD__.'(): data array argument was not an array.',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
            return false; //in case exception is caught
        }

        //build standard $data array
        $data = [
            'database'  => ( isset($data['database'])   ? $data['database']     : $this->getDatabaseName()  ),
            'hostname'  => ( isset($data['hostname'])   ? $data['hostname']     : $this->getHostname()      ),
            'limit'     => ( isset($data['limit'])      ? $data['limit']        : self::UPPER_LIMIT         ),
            'port'      => ( isset($data['port'])       ? $data['port']         : $this->getPortNumber()    ),
            'start'     => ( isset($data['start'])      ? $data['start']        : self::DEFAULT_OFFSET      ),
            'table'     => ( isset($data['table'])      ? $data['table']        : $this->getDefaultTable()  ),

            'results'   => null //this variable is reserved for the query results, so DO NOT USE IT!
        ];

        //merge extData array with the data array
        foreach ($extData as $value => $key) {
            if (in_array($key, ['results'])) { //this uses in_array() so new reserved variables can be defined
                throw new DatabaseException(
                    __METHOD__.'(): encountered extData parameter attempting to use a reserved name.',
                    DatabaseException::EXCEPTION_USING_RESERVED_KEYWORD,
                    $this
                );
                return false; //in case exception is caught
            }
            $data[$key] = $value; //insert value into $data array
        }

        //generate the final preparable statement
        $query = $this->genStmt(
            $sql['stmt'],
            ( isset($sql['tables'])         ? $data[$sql['tables']]         : null ),
            ( isset($sql['columns'])        ? $data[$sql['columns']]        : null ),
            ( isset($sql['sets'])           ? $data[$sql['sets']]           : null ),
            ( isset($sql['tablesets'])      ? $data[$sql['tablesets']]      : null ),
            ( isset($sql['columnsets'])     ? $data[$sql['columnsets']]     : null ),
            ( isset($sql['conditions'])     ? $data[$sql['conditions']]     : null ),
            $data['table']
        );

        //store prepared statement in $this->stmtTable
        if (!isset($this->stmtTable[$query])) {
            $this->stmtTable[$query] = $this->connector->prepare($query);
        }
        $stmt = $this->stmtTable[$query];

        //merge the arguments and sets into the bindable arguments array
        $args = [];
        foreach ($sql['args'] as $arg) {
            //TODO: How the hell am I supposed to know where to put the exploded sets?
            //NOTE: I very much regret my failure to use named parameters now. I knew it would happen eventually, and I was stupid to have waited this long. Fffffffiretruck.
        }

        //bind all arguments to the prepared statement
        foreach ($args as $arg) {
            //TODO: bind all arguments to prepared statement
        }

        $data['results'] = $stmt->execute();

        if (
            isset($sql['action']) &&
            is_array($sql['action']) &&
            count($sql['action']) > 0
        ) {
            $args = [];
            foreach ($sql['action']['args'] as $arg) {
                //Verify that both necessary parameters are present.
                if (!isset($arg['name'])) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered action argument without name parameter.',
                        DatabaseException::EXCEPTION_MISSING_DEFINITION,
                        $this
                    );
                    return false; //in case exception is caught
                }
                if (!isset($arg['value'])) {
                    throw new DatabaseException(
                        __METHOD__.'(): encountered action argument without value parameter.',
                        DatabaseException::EXCEPTION_MISSING_DEFINITION,
                        $this
                    );
                    return false; //in case exception is caught
                }

                $args[$arg['name']] = $data[$arg['value']]; //add value of variable to $args array at given name
            }
            $data['results'] = $this->doAction($sql['action']['id'], $args); //run the action
        }

        return $data['results'];

    }

    /**
     * DatabaseModel->schemaConform() Method
     *
     * Alter the entire database schema to conform with the given schema.
     *
     * @return bool True on success, false on failure.
     */
    public function schemaConform () {

        //TODO: Modify this and other schema-conformity methods to fit DatabaseModel.

        if ($this->hasSchema()) {
            if (!$this->schemaMatches()) {
                if ($this->schemaMatches()) {
                    return true; //schema already matches
                } else {
                    $output = true;

                    //conform the existing tables
                    foreach ($this->tables as $table) {
                        $thisResult = $this->tableConform($table);
                        if (!$thisResult) {
                            $output = false;
                        }
                    }

                    //conform the existing views
                    foreach ($this->views as $view) {
                        $thisResult = $this->viewConform($view);
                        if (!$thisResult) {
                            $output = false;
                        }
                    }

                    //conform the existing triggers
                    foreach ($this->triggers as $trigger) {
                        $thisResult = $this->triggerConform($trigger);
                        if (!$thisResult) {
                            $output = false;
                        }
                    }

                    return $output;
                }
            } else {
                return true; //schema already conforms.
            }
        } else {
            return false;
        }

    }

    /**
     * DatabaseModel->schemaMatches() Method
     *
     * Check if database schema matches given schema.
     *
     * @return bool True if they match, false if not.
     */
    public function schemaMatches () {

        //TODO: Implement check if schema object matches actual schema.

    }

    /**
     * DatabaseModel->select() Method
     *
     * Selects data from the database based on conditions and returns the
     * matching rows as the given columns.
     *
     * @param  array|string                   $columns       (optional) Columns to return. if left empty/null, will default to all columns (*)
     * @param  DatabaseCondition|array|string $conditions    (optional) Conditions to lookup. If left empty/null, will default to no conditions.
     * @param  int                            $start         (optional) Starting index from which to begin selecting. If left empty, defaults to 0.
     * @param  int                            $count         (optional) Maximum number of results to return. If left empty, defaults to no limit.
     * @param  string                         $sortBy        (optional) Column with which to sort the table. If left empty, defaults to the first column in the table.
     * @param  int                            $sortDirection (optional, Uses flags) direction to sort the table. If left empty, defaults to none. Options are SORT_NONE, SORT_ASC, SORT_DESC
     * @param  string                         $table         (optional if table set in constructor) Table from which to select.
     * @return array                                         Results of the select query as an associative array.
     * @throws DatabaseException                             If someone dun goofed.
     */
    public function select (
        $columns = ['*'],
        $conditions = null,
        $start = null,
        $count = null,
        $sortBy = null,
        $sortDirection = null,
        $table = null
    ) {

        //INPUT HANDLING

        //$columns validation
        $typeof_columns = gettype($columns);
        if ($typeof_columns == 'array') {
            //array is the desired input type, but needs no processing at this time
        } elseif ($typeof_columns == 'string') {
            $columns = str_getcsv($columns);
        } else {
            throw new DatabaseException(
                __METHOD__.'(): encountered columns argument of invalid type',
                DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                $this
            );
        }

        //$conditions validation
        $typeof_conditions = gettype($conditions);
        if ($conditions != null) {
            if ($typeof_conditions == 'object') {
                $classof_conditions == get_class($conditions);
                if ($classof_conditions == 'DatabaseCondition') {

                } else {
                    throw new DatabaseException(
                        __METHOD__.'(): object not of class DatabaseCondtion provdided for conditions argument.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
            } elseif ($typeof_conditions == 'array') {
                $conditions = new DatabaseCondition($conditions);
            } elseif ($typeof_conditions == 'string') {
                if (!$conditions = json_decode($conditions, true)) {
                    throw new DatabaseException(
                        __METHOD__.'(): string provided for conditions argument failed to be parsed as JSON.',
                        DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                        $this
                    );
                }
                $conditions = new DatabaseCondition($conditions);
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered conditions argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            $conditions = new $this->dbms['classes']['condition']([]); //default to no conditions
        }

        //$start validation
        if ($start != null) {
            if (is_int($start)) {
                if ($start < 0) {
                    throw new DatabaseException(
                        __METHOD__.'(): negative number given for start index argument.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered start index argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            $start = null;
        }

        //$count validation
        if ($count != null) {
            if (is_int($count)) {
                if ($count < 0) {
                    throw new DatabaseException(
                        __METHOD__.'(): negative number given for count argument.',
                        DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                        $this
                    );
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered count argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            $count = null;
        }

        //$start validation
        if ($start != null && $count == null) {
            //only start index is set
            $count = self::UPPER_LIMIT;
        } elseif ($start == null && $count != null) {
            //only row count is set
            $start = '0';
        }

        //$sortDirection validation
        if ($sortDirection != null) {
            if (
                $sortDirection != self::SORT_NONE &&
                $sortDirection != self::SORT_ASC &&
                $sortDirection != self::SORT_DESC
            ) {
                throw new DatabaseException(
                    __METHOD__.'(): invalid sort direction provided.',
                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                    $this
                );
            }
        } else {
            $sortDirection == self::SORT_NONE;
        }

        //$table validation
        $typeof_table = gettype($table);
        if ($table != null) {
            //table argument given
            if ($typeof_table == 'string') {
                //table argument is a string
                if (!$this->tableExists($table)) {
                    //table given in argument doesn't exist
                    throw new DatabaseException(
                        __METHOD__.'(): table "'.$table.'" does not exist.',
                        DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                        $this
                    );
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered table argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            //table argument not given
            if ($this->hasDefaultTable()) {
                $table = $this->getDefaultTable();
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): missing default table and/or table argument.',
                    DatabaseException::EXCEPTION_MISSING_DEFINITION,
                    $this
                );
            }
        }

        //$sortBy validation
        //this section is here and not ordered correctly because it needs to know validated $table
        if ($sortBy != null) {
            if (is_string($sortBy)) {
                if (!in_array($sortBy, $this->getColumns($table))) {
                    throw new DatabaseException(
                        __METHOD__.'(): sort column "'.$sortBy.'" does not exist.',
                        DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                        $this
                    );
                }
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): encountered sort-by argument of invalid type.',
                    DatabaseException::EXCEPTION_INPUT_INVALID_TYPE,
                    $this
                );
            }
        } else {
            $sortBy == $this->getColumns($table)[0];
        }

        //COLUMN KEYWORD DETECTION
        if (in_array('*', $columns) || in_array('ALL', $columns)) {
            $columnKeyword = self::KEYWORD_ALL;
        } else {
            //CUSTOM SANITIZATION
            //(for stuff that can't be parameterized in a prepared statement)
            $columnCount = count($columns);
            for ($i = 0; $i < $columnCount; $i++) {
                if (!$this->columnExists($columns[$i])) {
                    throw new DatabaseException(
                        __METHOD__.'(): column "'.$columns[$i].'" does not exist.',
                        DatabaseException::EXCEPTION_DB_ITEM_DOES_NOT_EXIST,
                        $this
                    );
                    unset($columns[$i]);
                }
            }
            $columns = array_values($columns); //normalize the columns (to fix gaps)

            //add columns to execution arrays
            $executionTypeArray = [];
            for ($i = 0; $i < count($columns); $i++) {
                $executionTypeArray[] = PDO::PARAM_STR;
            }
            $executionArray = $columns;
        }

        //DATABASE EXECUTION
        //build statement
        $query  = 'SELECT ';
        if (!$columnKeyword) {
            $query .= implode(', ', $columns); //this is being sanitized by checking that all values actually exist
        } else {
            if ($columnKeyword == self::KEYWORD_ALL) {
                $query .= '*';
            } else {
                throw new DatabaseException(
                    __METHOD__.'(): invalid keyword caught (check columns argument).',
                    DatabaseException::EXCEPTION_INPUT_NOT_VALID,
                    $this
                );
            }
        }
        $query .= ' FROM '.$table;
        $query .= (strlen($conditions->getStatement()['stmt'] > 0) ? ' WHERE ' : '');
        $query .= (string)$conditions->getStatement()['stmt'];
        $query .= $start != null && $count != null ? ' LIMIT ?, ?' : '';
        $query .= ';';

        //add limit parameters to execution arrays
        if ($start != null && $count != null) {
            $executionArray[] = $start;
            $executionTypeArray[] = PDO::PARAM_INT;
            $executionArray[] = $count;
            $executionTypeArray[] = PDO::PARAM_INT;
        }
        $executionArray = array_merge($executionArray, $conditions->getStatement()['args']);

        try {
            //prepare statement and execute
            $stmt = $this->connector->prepare($query);
            for ($i = 0; $i < count($executionArray); $i++) {
                $stmt->bindValue($i + 1, $executionArray[$i], $executionTypeArray[$i]);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new DatabaseException(
                __METHOD__.'(): caught exception thrown by PDO.',
                DatabaseException::EXCEPTION_GENERIC_DATABASE_ERROR,
                $this,
                $e
            );
        }

        //return result
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    /**
     * DatabaseModel->tableConform() Method
     *
     * Alter the table to conform with the given schema.
     *
     * @param  [type] $table [description]
     * @return [type]        [description]
     */
    public function tableConform ($table = null) {

        if ($this->hasSchema()) {
            if (!$this->tableMatches($table)) {
                if ($this->tableMatches($table)) {
                    return true; //table already matches
                } else {
                    //TODO: Figure this out
                }
            } else {
                return true; //table already conforms
            }
        } else {
            return false;
        }

    }

    /**
     * DatabaseModel->tableExists() Method
     *
     * Check if table exists in the database.
     *
     * @param  string            $table The name of the table for which to check.
     * @return bool              True: table exists | False: table does not exist.
     * @throws DatabaseException If someone dun goofed.
     */
    public function tableExists ($table) {

        //TODO: Decentralize the DBMS-specific implementations of tableExists
        if ($this->type == self::TYPE_MYSQL) {
            $query = 'SHOW TABLES LIKE ?;';
            $stmt = $this->connector->prepare($query);
            $stmt->bindParam(1, $table, PDO::PARAM_STR);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($results > 0)) {
                return true;
            } else {
                return false;
            }
        } elseif ($this->type == self::TYPE_PGSQL) {
            $query = '
                SELECT EXISTS (
                    SELECT  1
                    FROM    pg_catalog.pg_class c
                    JOIN    pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE   n.nspname = ?
                    AND     c.relname = ?
                    AND     c.relkind = \'r\'
                );
            ';
            $stmt = $this->connector->prepare($query);
            $stmt->bindParam(1, $this->name, PDO::PARAM_STR);
            $stmt->bindParam(2, $table, PDO::PARAM_STR);
            $stmt->execute();
            $results = $stmt->fetch();
            if ($results) {
                return true;
            } else {
                return false;
            }
        } elseif ($this->type == self::TYPE_SQLITE) {
            $query = 'SELECT name FROM sqlite_master WHERE type=\'table\' AND name=?;';
            $stmt = $this->connector->prepare($query);
            $stmt->bindParam(1, $table, PDO::PARAM_STR);
            $stmt->execute();
            $results = $stmt->fetchAll();
            if (count($results > 0)) {
                return true;
            } else {
                return false;
            }
        } else {
            throw new DatabaseException(
                __METHOD__.'(): invalid database type in object.',
                DatabaseException::EXCEPTION_CORRUPTED_OBJECT,
                $this
            );
        }

    }

    /**
     * DatabaseModel->tableMatches() Method
     *
     * Check if table matches given schema.
     *
     * @param  [type] $table [description]
     * @return [type]        [description]
     */
    public function tableMatches ($table = null) {

        //TODO: Implement check if schema for given table in object matches actual schema.

    }

    /**
     * DatabaseModel->triggerConform() Method
     *
     * Alter trigger to conform with given schema.
     *
     * @param  [type] $trigger [description]
     * @return [type]          [description]
     */
    public function triggerConform ($trigger) {

        if ($this->hasSchema()) {
            if (!$this->tableMatches($trigger)) {
                if ($this->triggerMatches($trigger)) {
                    return true; //trigger already matches
                } else {
                    //TODO: Figure this out
                }
            } else {
                return true; //trigger already conforms
            }
        } else {
            return false;
        }

    }

    /**
     * DatabaseModel->triggerMatches() Method
     *
     * Check if trigger matches given schema.
     *
     * @param  [type] $trigger [description]
     * @return [type]          [description]
     */
    public function triggerMatches ($trigger) {

        //TODO: Implement trigger conformity checker.

    }

    /**
     * DatabaseModel->viewConform() Method
     *
     * Alter view to conform with given schema.
     *
     * @param  [type] $view [description]
     * @return [type]       [description]
     */
    public function viewConform ($view) {

        //TODO: Implement view conformity forcer.

    }

    /**
     * DatabaseModel->viewMatches() Method
     *
     * Check if view matches the given schema.
     *
     * @param  [type] $view [description]
     * @return [type]       [description]
     */
    public function viewMatches ($view) {

        //TODO: Implement view conformity checker.

    }

}

?>
