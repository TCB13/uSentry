<?php
/* uSentry Configuration
 * Please refer to the README file or index.php to learn how to deploy uSentry 
 * and configure your web server.
 */

$sessionLifetime = 1 * 24 * 60 * 60; // 1 day

/* 
 * Generate passwords:
 * php -r 'echo password_hash("xxxxxxx", PASSWORD_DEFAULT);'
*/
$credentials = [
    [
        "username"   => "User1",
        "password"   => '$2y$2$000000000000000000001',
        "authorized" => [
            "https://your-local-domain-or-ip/filebrowser",
            "https://your-local-domain-or-ip/syncthing"
        ]
    ],
    [
        "username"   => "User2",
        "password"   => '$2y$2$000000000000000000002',
        "authorized" => [
            "https://your-local-domain-or-ip/filebrowser"
        ]
    ]
];
