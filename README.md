# 🛡️ uSentry - Identity & Access Management
uSentry is a lightweight, self-hosted Identity and Access Management (IAM) and Single Sign-On (SSO) solution designed for homelab and small-scale environments.

**⚡ A single PHP file. < 400 lines of code. No database. No background processes. No cloud. Just works. ⚡**

![usentry](https://github.com/user-attachments/assets/512a17fe-bee1-49b8-af23-cb392d0acbb9)

## 🚀 Why uSentry?
Most IAM and SSO solutions require databases, certificates and background services baked into a dozen containers. This is all fine but also also overkill for homelabs and impossible for low-power ARM devices. 
uSentry is different, it *isn't pretty but it sucks less* for a lot of use cases:

- 🧱 Single PHP file with less than 400 lines of code — Easy to read, easy to audit, easy to deploy
- 🍃 No persistent processes — Nothing running in the background, no wasted resources
- 💡 Designed for low-power devices — Runs perfectly on a Raspberry Pi or similar
- 💾 No database required — All users stored right into the code
- 🌐 Privacy-focused — Offline first, 100% local, no cloud, no external services, no internet access required
- ⚙️ Define users and permissions — Control access per user and per app (domain, url)
- 🕵️ SSO-ready — Authenticate once, access all apps

## ⚙️ How Does It Work?
Nginx has the ability to authenticate each request with an external solution. For example, if I try to browse to https://your-local-domain-or-ip/, Nginx can call another URL to check if it should allow access or not. 
uSentry provides this functionality along with sessions and login/logout facilities. Check out https://nginx.org/en/docs/http/ngx_http_auth_request_module.html for more details.

## 💧 Requirements
- ✅ Nginx (with ngx_http_auth_request_module)
- ✅ PHP (7.4+)

## 🛠️ Installation & Configuration

1. Deploy the single `index.php` file to a directory, eg. `/web/usentry`
2. Add your users and apps in `index.php`:
```php
$credentials = [
    [
        "username"   => "User1",
        "password"   => '$2y$2$000000000000000000001',
        "authorized" => [
            "https://your-local-domain-or-ip/filebrowser",
            "https://your-local-domain-or-ip/syncthing"
        ]
    ]
];
```
   
3. Add uSentry to your Nginx configuration (`/etc/nginx/conf.d/default.conf`):
```
# uSentry Identity & Access Management ------------------------->
location  ^~ /usentry/login {
    alias /web/usentry;
    auth_request off;
    index index.php;
    try_files $uri $uri/ index.php;
    location ~ ^.+?\.php(/.*)?$ {
       include /etc/nginx/fastcgi_params;
       fastcgi_pass unix:/run/php/php7.4-fpm.sock;
       fastcgi_split_path_info ^(.+\.php)(/.*)$;
       set $path_info $fastcgi_path_info;
       fastcgi_param PATH_INFO $path_info;
      fastcgi_param SCRIPT_FILENAME $request_filename;
    }
}
location = /usentry/auth_request {
    internal;
    proxy_pass https://your-local-domain-or-ip/usentry/login/?action=status;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header X-Original-URI $scheme://$host$request_uri;
}
error_page 401 = @error401;
location @error401 {
    return 302 /usentry/login/?code=401&redirect=$scheme://$host$request_uri;
}
error_page 403 = @error403;
location @error403 {
   return 302 /usentry/login/?code=403&redirect=$scheme://$host$request_uri;
}
# uSentry Identity & Access Management <-------------------------
```
4. Protect your websites / apps:
```
# Example 1: protect Filebrowser, on a reverse proxy:
location /files {
    auth_request /usentry/auth_request;
    proxy_redirect off;
    client_max_body_size 0;
    proxy_request_buffering off;
    proxy_pass http://127.0.0.1:4100;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Username "fbinternaluser"; # This is the internal user used by filebrowser
}

# Example 2: protect Syncthing, on a reverse proxy (requires internal basic auth):
location /syncthing/ {
    auth_request /usentry/auth_request;
    proxy_pass http://127.0.0.1:46712/;
    proxy_set_header Host "localhost";
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Authorization "Basic MTIzOjEyMw=="; # Syncthing internal authentication hash here - SHA56(username:password)
}
```

🥳 **That's it, you're done!** Now access your app and uSentry will ask for credentials. 🥳

## ❓ FAQ
- **Does it support 2FA?** Not yet
- **Can I use it with [X service]?** If it's a simple website, PHP solution or you can configure Nginx as a reverse proxy to your services, probably yes
- **Is it secure?** It's secure enough for most homelabs/low-risk use cases. Review the code yourself — it's <400 lines!
- **How do I logout?** Open https://your-local-domain-or-ip/usentry/login?action=logout or clear your cookies.

-------------
Made with ❤️, simplicity (sanity) and PHP.
