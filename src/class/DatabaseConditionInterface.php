<?php

interface DatabaseConditionInterface {

    //Protected Members
    protected $dbmsGrammarTable; //array that maps query grammar to elements (see DatabaseConditionModel.php for an example)
    protected $standardGrammarTable;

    //Public Methods
    public function __construct ($struct=[]);
    public function __invoke ();
    public function __toString ();
    public function add ($rule); //add rule to conditional statement
    public function del ($rule); //delete rule from conditional statement
    public function getStatement ();
    public function getStructure ();
    public function parse (array $array, $encap=false); //parse array to statement in target language

    //Protected Methods
    protected function getGrammar();

}

?>
