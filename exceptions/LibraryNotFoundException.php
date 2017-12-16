<?php

class LibraryNotFoundException extends Exception
{
    public function __construct($msg)
    {
        parent::__construct($msg);
    }
}