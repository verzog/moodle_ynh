<?php

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = '__DB_NAME__';
$CFG->dbuser    = '__DB_USER__';
$CFG->dbpass    = '__DB_PWD__';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist'   => 0,
    'dbsocket'    => '',
    'dbport'      => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot   = 'https://__DOMAIN____PATH__';
$CFG->dataroot  = '__DATA_DIR__';
$CFG->admin = 'admin';

$CFG->directorypermissions = 02777;

// Moodle 5.1+ front-controller router. The NGINX config routes non-file
// requests to r.php (try_files ... /r.php), so declare the router configured.
$CFG->routerconfigured = true;

require_once(__DIR__ . '/lib/setup.php'); // Do not edit

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
