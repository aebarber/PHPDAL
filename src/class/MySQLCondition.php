<?php

class MySQLCondition extends DatabaseConditionModel implements DatabaseConditionInterface {

    protected $dbmsGrammarTable = [
        'quoteIdentLeft'    => '`', //string to be placed at the left of an identifier
        'quoteIdentRight'   => '`' //string to be placed at the right of an identifier
    ];

}

?>
