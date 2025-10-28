<?php

/*
 * Configure NGINX:
 *  # uSentry Identity & Access Management Rules ------------------------->
 *  location  ^~ /usentry/login {
 *      alias /mnt/SSD1/web/usentry;
 *      auth_request off;
 *      index index.php;
 *      try_files $uri $uri/ index.php;
 *      location ~ ^.+?\.php(/.*)?$ {
 *          include /etc/nginx/fastcgi_params;
 *          fastcgi_pass unix:/run/php/php7.4-fpm.sock;
 *          fastcgi_split_path_info ^(.+\.php)(/.*)$;
 *          set $path_info $fastcgi_path_info;
 *          fastcgi_param PATH_INFO $path_info;
 *          fastcgi_param SCRIPT_FILENAME $request_filename;
 *      }
 *  }
 *  location = /usentry/auth_request {
 *      internal;
 *      proxy_pass https://your-local-domain-or-ip/usentry/login/?action=status;
 *      proxy_pass_request_body off;
 *      proxy_set_header Content-Length "";
 *      proxy_set_header X-Original-URI $scheme://$host$request_uri;
 *  }
 *  error_page 401 = @error401;
 *  location @error401 {
 *      # If you're using WebDAV uncomment the following lines
 *      #if ($request_method ~* "^(PROPFIND|PUT|DELETE|MKCOL|COPY|MOVE|LOCK|UNLOCK|OPTIONS)$") {
 *      #    return 401;
 *      #}
 *      return 302 /usentry/login/?code=401&redirect=$scheme://$host$request_uri;
 *  }
 *  error_page 403 = @error403;
 *  location @error403 {
 *      # If you're using WebDAV uncomment the following lines
 *      #if ($request_method ~* "^(PROPFIND|PUT|DELETE|MKCOL|COPY|MOVE|LOCK|UNLOCK|OPTIONS)$") {
 *      #    return 403;
 *      #}
 *      return 302 /usentry/login/?code=403&redirect=$scheme://$host$request_uri;
 *  }
 *  # uSentry Identity & Access Management Rules <-------------------------
 *
 */

 /*
 * Usage Examples:
 * 
 * Protect simple PHP website:
 * 
 * location /webpage {
 *     auth_request /usentry/auth_request;
 *     alias /web/webpage/public;
 *     index index.html index.htm index.php;
 *     try_files $uri $uri/ index.php;
 *     (... the rest of your php configuration here)
 * }
 * 
 * Protect Filebrowser, on a reverse proxy:
 *
 * location /files {
 *     auth_request /usentry/auth_request;
 *     proxy_redirect off;
 *     client_max_body_size 0;
 *     proxy_request_buffering off;
 *     proxy_pass http://127.0.0.1:4100;
 *     proxy_set_header Host $host;
 *     proxy_set_header X-Real-IP $remote_addr;
 *     proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
 *     proxy_set_header X-Username "fbinternaluser"; # This is the internal user used by filebrowser
 * }
 *
 * Protect Syncthing, on a reverse proxy (requires internal basic auth):
 *
 * location /syncthing/ {
 *     auth_request /usentry/auth_request;
 *     proxy_pass http://127.0.0.1:46712/;
 *     proxy_set_header Host "localhost";
 *     proxy_set_header X-Real-IP $remote_addr;
 *     proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
 *     proxy_set_header X-Forwarded-Proto $scheme;
 *     proxy_set_header Authorization "Basic MTIzOjEyMw=="; # Syncthing internal authentication hash here - SHA56(username:password)
 * }
 */

/* uSentry Configuration */
require_once("config.php");

//  --------------------- No need to edit anything bellow this line ---------------------
ini_set('session.use_strict_mode', '1');
session_name("uSentryIAM");
session_set_cookie_params(0);
session_start();

// Main action handler
if (isset($_REQUEST['action'])) {
    switch ($_REQUEST['action']) {
        case 'status':
            $authTest = getAuthStatus(true);
            // 200 => autorized | 401 => login required | 403 => user ok but can't access endpoint
            exit(http_response_code($authTest));
            break;
        case 'login':
            login();
            break;
        case 'logout':
            logout();
            break;
    }
}

function login()
{
    global $sessionLifetime;
    $res = getUser($_REQUEST['username']);

    if ($res !== false && password_verify($_REQUEST['password'], $res['password'])) {
        $_SESSION['uSentry-Authenticated'] = true;
        $_SESSION['uSentry-User'] = $res['username'];
        $_SESSION['uSentry-Timestamp'] = time();

        // Set expiracy date
        if (isset($_REQUEST['remember-me'])) {
            $params = session_get_cookie_params();
            $expires = time() + $sessionLifetime;
            setcookie(session_name(), session_id(), $expires, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }

        if (!empty($_REQUEST['redirect'])) {
            header("Location: " . $_REQUEST['redirect']);
            exit();
        }
        return;
    }

    logout();
}

function logout()
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    session_destroy();
    session_write_close();
}

function getUser($username)
{
    global $credentials;
    $filtered = array_filter($credentials, function ($item) use ($username) {
        return isset($item["username"]) && $item["username"] == $username;
    });
    return empty($filtered) ? false : array_values($filtered)[0];
}

function getAuthStatus($originFromHeader = false)
{
    if (!isset($_SESSION['uSentry-Authenticated'])) {
        return 401;
    }
    // Check if the user is authorized on the requested URL
    $userData = getUser($_SESSION['uSentry-User']);
    if ($userData === false) {
        logout();
        return 401;
    }

    $targetOrigin = [];
    if ($originFromHeader) {
        // This is a NGINX sub-request
        if (!isset($_SERVER['HTTP_X_ORIGINAL_URI'])) {
            logout();
            return 401;
        }
        $targetOrigin = $_SERVER['HTTP_X_ORIGINAL_URI'];
    } else {
        // This is a final redirect to display a 403 page
        if (isset($_GET['redirect'])) {
            $targetOrigin = $_GET['redirect'];
        }
    }
    $ocurrences = array_filter($userData["authorized"], function ($str) use ($targetOrigin) {
        return strpos($targetOrigin, $str) === 0;
    });
    if (empty($ocurrences)) {
        return 403; // Login OK but not authorized for this endpoint
    }

    // Manage sesson time - clear after 24 hours.
    global $sessionLifetime;
    if (($_SESSION['uSentry-Timestamp'] + $sessionLifetime) > time()) {
        return 200;
    }
    logout();
    return 401;
}

?>

<!DOCTYPE html>
<html lang="en-us">
<head>
    <meta charset="utf-8">
    <meta charset="UTF-8">
    <meta name="viewport"
          content="viewport-fit=cover, user-scalable=0, width=device-width, initial-scale=1, maximum-scale=1">

    <title>uSenty - Authentication</title>
    <style>
        *, *:before, *:after {
            box-sizing: border-box !important;
        }

        html {
            touch-action: manipulation;
        }

        body {
            font-family: sans-serif;
            font-size: 17px;
            line-height: 1.5;
            background-color: #17212B;
            color: #fff;
            margin: 0;
            padding: 0;
        }

        h1 {
            font-size: 25px;
        }

        input:not([type="checkbox"]) {
            all: unset;
            padding: 0 5px;
            border: 1px solid #fff;
            line-height: 2.2;
            margin-bottom: 10px;
            width: 100%;
        }

        input[type="submit"], button {
            all: unset;
            margin-top: 17px;
            padding: 10px 10px;
            background-color: #3993ea;
            display: inline-block;
            line-height: 1.5;
            cursor: pointer;
            width: 100%;
            max-width: var(--mobile-breakpoint);
            text-align: center;
        }

        input[type="submit"]:hover, button:hover {
            background-color: #3484d2;
        }

        div.wrapper {
            display: flex;
            flex-flow: row wrap;
            justify-content: center;
            align-items: center;
            height: 100vh;
            padding: 10px;
        }

        form {
            max-width: 300px;
        }

        form p.message {
            font-weight: bold;
        }

        input[type="checkbox"] ~ label {
            font-size: 15px;
        }

        .logo {
            margin-bottom: 20px;
            overflow: hidden;
        }

        .logo * {
            display: block;
            float: left;
        }

        .logo h1:first-of-type {
            font-size: 50px;
            margin-right: 10px;
            line-height: 70px;
        }

        .logo h1 {
            margin: 0;
            font-size: 40px;
            line-height: 1.1;
        }

        .logo p {
            font-size: 12px;
            margin: 0;
        }

        footer {
            position: absolute;
            width: 100%;
            bottom: 0;
            color: #6c757d;
        }

        footer p {
            text-align: center;
            font-size: 12px;
        }

    </style>
</head>

<body>
<div class="wrapper">
    <form method="post">
        <div class="logo">
            <h1>🛡️</h1>
            <h1>uSentry</h1>
            <p>Identity & Access Management</p>
        </div>
        <?php $authStatus = getAuthStatus(); if ($authStatus === 200 || $authStatus === 403): ?>
            <input type="hidden" name="action" value="logout">
            <?php if ($authStatus === 403): ?>
            <p class="message">You're not authorized to access the requested resource.</p>
            <?php endif; ?>
            <button>Logout</button>
        <?php else: ?>
            <p class="message">Authentication Required</p>
            <input type="hidden" name="action" value="login">
            <input type="text" name="username" placeholder="Username" autofocus>
            <input type="password" name="password" placeholder="Password">
            <input type="checkbox" id="remember-me" name="remember-me" checked />
            <label for="remember-me">Remember me</label>

            <button>Login</button>
        <?php endif; ?>
    </form>
</div>

<footer>
    <p>uSentry IAM • <?php print gethostname(); ?> • <?php print date('Y-m-d H:i:s'); ?> • <a href="https://github.com/TCB13/uSentry" target="_blank">github.com/TCB13/uSentry</a></p>
</footer>
</body>
</html>
