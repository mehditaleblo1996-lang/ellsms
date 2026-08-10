# Auth, 2FA, bootstrap-admin, user/KYC management

## Login + 2FA

```mermaid
flowchart TD
    A["GET/POST /login.php<br/>login.php:1"] --> B{"current_user()?<br/>login.php:4"}
    B -->|yes| B1["redirect /index.php"]
    B -->|no| C{"ellsms_has_admin()?<br/>login.php:7"}
    C -->|no admin yet| C1["redirect /bootstrap-admin.php"]
    C -->|yes| D{"POST?"}
    D -->|no| E["render login form<br/>login.php:56-65"]
    D -->|yes| F["csrf_check()<br/>login.php:11 / bootstrap.php:151"]
    F --> G["SELECT id,password,mobile,active,deleted<br/>FROM user_ WHERE username=?<br/>login.php:12-14"]
    G --> H{"exists & active & !deleted &<br/>backend_verify_password()?<br/>login.php:16 / bootstrap.php:71"}
    H -->|no| H1["usleep(400000)<br/>generic error<br/>login.php:17-18"]
    H -->|yes| I["SELECT panel_access,twofa_enabled<br/>FROM ellsms_meta WHERE user_id=?<br/>login.php:20-22"]
    I --> J{"panel_access?<br/>login.php:23"}
    J -->|no| J1["error: no panel access"]
    J -->|yes| K{"twofa_enabled?<br/>login.php:25"}
    K -->|no| K1["session_regenerate_id()<br/>set $_SESSION[uid]<br/>audit(login)<br/>login.php:36-39"]
    K -->|yes| L["send_2fa_code()<br/>backend.php:372-393<br/>INSERT ellsms_2fa_codes"]
    L --> M["redirect /verify-2fa.php<br/>login.php:31-33"]
    M --> N["GET/POST /verify-2fa.php<br/>verify-2fa.php:1"]
    N --> O{"twofa_uid in session?"}
    O -->|yes| P["SELECT user_ by id<br/>verify-2fa.php:9-11"]
    P --> R{"attempts >= 5? (magic number)<br/>verify-2fa.php:35"}
    R -->|no| S["verify_2fa_code()<br/>backend.php:396-408"]
    S -->|match| S1["consume code, set session, audit<br/>verify-2fa.php:40-43"]
    S -->|no match| S2["attempts++, usleep(400000)"]
    S1 --> T["redirect /index.php"]
```

## bootstrap-admin.php race

```mermaid
flowchart TD
    A["GET/POST /bootstrap-admin.php"] --> B{"ellsms_has_admin()?<br/>bootstrap.php:135-137"}
    B -->|yes| B1["redirect /login.php"]
    B -->|no| E["csrf_check() + verify credentials<br/>bootstrap-admin.php:10-17"]
    E --> H["RE-CHECK ellsms_has_admin()<br/>bootstrap-admin.php:18<br/>*** NO LOCK / NO TRANSACTION ***"]
    H -->|still none| I["INSERT ellsms_meta is_admin=1<br/>bootstrap-admin.php:19-22"]
    I --> J["session + audit + redirect"]
    H -.->|"concurrent request B,<br/>different username,<br/>same instant"| I2["INSERT for user B also succeeds<br/>=> TWO admins created"]
```

## User management / KYC admin edit

```mermaid
flowchart TD
    A["/users.php"] --> B["require_admin()"]
    B --> C{"POST?"}
    C -->|yes| D["csrf_check()"]
    D --> E{"do=?"}
    E -->|grant| F["lookup by username, INSERT/UPDATE ellsms_meta"]
    E -->|revoke / toggle_admin<br/>(self-check present)| G["UPDATE ellsms_meta<br/>*** id !== me only ***"]
    E -->|toggle_2fa / originator /<br/>credit / password / kyc_save<br/>(NO self-check, NO existence check)| H["UPDATE ellsms_meta / user_ / ellsms_user_kyc<br/>WHERE id = $id<br/>*** never checked against panel_access=1 ***"]
    E -->|create_account| I["backend_create_account() POST /api/users/"]
    I -->|201| K["INSERT ellsms_meta<br/>*** not transactional with API call ***"]
    C -->|no, GET| N{"$_GET[edit] set?"}
    N -->|yes| O["SELECT u.*,m.* FROM user_ LEFT JOIN ellsms_meta<br/>WHERE u.id = $_GET[edit]<br/>*** NO WHERE panel_access=1 ***<br/>=> any user_ row in shared DB loadable"]
    N -->|no| Q["SELECT panelUsers WHERE panel_access=1 (correctly scoped)"]
```

External dependencies: `app/bootstrap.php` (db, current_user, csrf, backend_hash/verify_password, kyc_store_upload), `app/backend.php` (send_2fa_code, verify_2fa_code, backend_create_account).
