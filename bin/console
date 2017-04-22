#!/usr/bin/env php
<?php

use \TracHandler\Command\AddCSVToGithubCommand;

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();


$application->add( new AddCSVToGithubCommand( ) );
$application->run();