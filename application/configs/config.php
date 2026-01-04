<?php
/**
*  aMember Pro Config File
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    FileName $RCSfile$
*    Release: 6.3.5 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forums
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*/

const AM_USE_NEW_CSS = 1;

return [
    'db' => [
        'mysql' => [
            'db'    => 'amember',
            'user'  => 'amember',
            'pass'  => 'amember',
            'host'  => 'mysql',
            'prefix' => 'am_',
            'port'  => '',
            'pdo_options' => [],
        ],
    ],
    'cache' => [
        'type' => '',
        'options' => []
    ]
];