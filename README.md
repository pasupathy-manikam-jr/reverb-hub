# Reverb Hub

One [Laravel Reverb](https://laravel.com/docs/reverb) WebSocket server shared by several apps (bayar-siap first).
Each app has its own id/key/secret and allowed browser origins, all in `.env`. Apps can't see each other's channels.

## Add an app

```sh
php artisan reverb:add-app bayar-siap --origins="example.com,*.example.com"
php artisan config:clear   # or config:cache in production
php artisan reverb:restart # the service manager starts it again
```

This appends the app to `REVERB_APPS` plus a `REVERB_APP_<NAME>_ID/_KEY/_SECRET/_ORIGINS` block in this server's `.env`,
then prints the lines to paste into the app's own `.env`.

- `--origins`: hostnames only (no scheme/port), `*` wildcards allowed, `*` alone allows any site. With no origins set, browsers are refused.
- Clients that send no `Origin` header (native Android/iOS SDKs, servers) are always let in. Origin is a browser-only check, and a non-browser client can send any Origin it likes, so blocking them would add no protection. What protects private and presence channels is the app's own `/broadcasting/auth` signing with its secret.
- To rotate a secret or change origins, edit the block in `.env`, then clear config and restart.

## Client setup

**Laravel app** (sending events): paste the printed lines into its `.env`, then `composer require laravel/reverb` there
or set up the `reverb` broadcaster in `config/broadcasting.php`. It sends events over HTTP to `REVERB_HOST:REVERB_PORT`.

**Browser** (Echo): `broadcaster: 'reverb'` with the `VITE_REVERB_*` values.

**Android** ([pusher-websocket-java](https://github.com/pusher/pusher-websocket-java)):

```kotlin
val options = PusherOptions()
    .setHost("<reverb-host>").setWssPort(443).setUseTLS(true)
    .setChannelAuthorizer(HttpChannelAuthorizer("https://<app>/broadcasting/auth")) // private channels
val pusher = Pusher("<REVERB_APP_KEY>", options)
```

**iOS** ([pusher-websocket-swift](https://github.com/pusher/pusher-websocket-swift)):

```swift
let options = PusherClientOptions(
    authMethod: .endpoint(authEndpoint: "https://<app>/broadcasting/auth"),
    host: .host("<reverb-host>"), port: 443, useTLS: true)
let pusher = Pusher(key: "<REVERB_APP_KEY>", options: options)
```

Leave the cluster unset. For phones, the app's `/broadcasting/auth` route has to accept token (Sanctum) auth, not just session cookies.

## Run locally

```sh
php artisan reverb:start --debug          # ws://127.0.0.1:8080
cd tests/e2e && npm i && node e2e.mjs     # end-to-end smoke test (needs 2 apps in REVERB_APPS)
php artisan test
```

The smoke test connects pusher-js clients without an Origin header (as native SDKs do) and subscribes to a public channel
and a private one. It then publishes through `pusher/pusher-php-server` (the same library Laravel's broadcaster uses)
and checks that:

- the events arrive
- a second app doesn't receive them
- a wrong secret, an unknown key and a foreign Origin are all rejected

## Hosting (needs root / WHM)

A cPanel account can't keep a long-running process alive, so this needs root.

1. **Code**: deploy to e.g. `/home/<user>/reverb-hub` as usual (`composer install --no-dev`, `.env`, `php artisan config:cache`).
   It needs no database and no frontend build. In `.env`: `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`,
   `REVERB_HOST=<reverb-host>`, `REVERB_PORT=443`, `REVERB_SCHEME=https`.

2. **systemd** `/etc/systemd/system/reverb-hub.service`:

   ```ini
   [Unit]
   Description=Reverb Hub
   After=network.target

   [Service]
   User=<user>
   WorkingDirectory=/home/<user>/reverb-hub
   ExecStart=/usr/local/bin/php artisan reverb:start --no-interaction
   Restart=always
   RestartSec=3
   LimitNOFILE=10000

   [Install]
   WantedBy=multi-user.target
   ```

   `systemctl daemon-reload && systemctl enable --now reverb-hub`. Check the PHP path with `which php` (or use `/opt/cpanel/ea-php84/root/usr/bin/php`).
   Past about 1,000 concurrent connections, install `ext-uv` (Reverb picks it up automatically).

3. **Apache proxy** (WHM → EasyApache 4: make sure `mod_proxy` and `mod_proxy_wstunnel` are on). Add a vhost include for
   `<reverb-host>` (a subdomain with AutoSSL) at
   `/etc/apache2/conf.d/userdata/ssl/2_4/<user>/<reverb-host>/reverb.conf`:

   ```apache
   ProxyPreserveHost On
   ProxyPass        /app  ws://127.0.0.1:8080/app
   ProxyPassReverse /app  ws://127.0.0.1:8080/app
   ProxyPass        /apps http://127.0.0.1:8080/apps
   ProxyPassReverse /apps http://127.0.0.1:8080/apps
   ```

   `/app` carries the WebSocket connections and `/apps` is the HTTP API that apps publish events through.
   Then run `/scripts/rebuildhttpdconf && systemctl restart httpd`.
