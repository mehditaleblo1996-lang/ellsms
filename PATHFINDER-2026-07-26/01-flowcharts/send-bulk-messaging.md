# Send / new-send / bulk (p2p, smart, gradual) / legacy URL API

## Direct send

```mermaid
flowchart TD
  A["POST /send.php or /new-send.php"] --> B["csrf_check()"]
  B --> C["parse_destinations($_POST['destinations'])<br/>bootstrap.php:206"]
  C --> D["append group members (normalized)<br/>send.php:29-36 / new-send.php:35-39"]
  D --> E["append category members<br/>*** NOT normalized *** send.php:38-42 / new-send.php:40-44"]
  E --> F["array_unique dests"]
  F --> G{"new-send.php: use_blacklist?"}
  G -- yes --> H["filter_blacklist() bootstrap.php:506"]
  G -- no --> I
  H --> I["mode=now?"]
  I -- later/recurring --> J["INSERT ellsms_schedule"]
  I -- now --> K["dispatch_message() backend.php:100"]
  K --> L["normalize_originator() backend.php:104"]
  L --> M["sms_parts()*count(dests)=cost backend.php:107-108"]
  M --> N{"role!=admin && credit<cost? backend.php:110 (TOCTOU, no row lock)"}
  N -- yes --> O["reject"]
  N -- no --> P["backend_api_send() POST /api/messages/send"]
  P --> Q["UPDATE user_ SET currentcredit -= cost backend.php:138-144"]
```

## new-send.php — 3 modes (direct / recurring / gradual)

```mermaid
flowchart TD
  A["POST /new-send.php"] --> B["mode=direct|recurring|gradual"]
  B --> C["build dests (group/category/manual)"]
  C --> D{"use_blacklist?"}
  D -- yes --> E["filter_blacklist()"]
  D -- no --> F
  E --> F{"save_campaign?"}
  F -- yes --> G["INSERT ellsms_campaigns (untransacted with send outcome)"]
  F -- no --> H
  G --> H{"mode?"}
  H -- direct --> I["dispatch_message()"]
  H -- recurring --> J["INSERT ellsms_schedule"]
  H -- gradual --> K["build items[], same content per dest"]
  K --> L["bulk_queue_job(...,'gradual',throttleCount,throttleMinutes)<br/>backend.php:494"]
  L --> M["*** duplicate stale credit check *** backend.php:503-505"]
  M --> N["TX: INSERT ellsms_bulk_jobs + N x ellsms_bulk_items"]
  N --> O["redirect /p2p-send.php"]
  O --> P["*** p2p-send.php WHERE type='p2p' only ***<br/>gradual job is invisible / uncancellable anywhere in the UI"]
```

## p2p / smart upload -> queue -> worker

```mermaid
flowchart TD
  A["POST p2p-send.php / smart-send.php do=upload"] --> B["csrf_check()"]
  B --> C["normalize_originator()"]
  C --> D["validate $_FILES['file']"]
  D --> E["read_spreadsheet_rows() -> xlsx_reader.php"]
  E --> F["ZipArchive open + FULL decompress<br/>xlsx_reader.php:50-96<br/>*** no size/entry cap before decompression ***"]
  F --> G["rows fully materialized in memory"]
  G --> H{"row count cap AFTER full parse<br/>p2p: >20000 / smart: >20001 (divergent literals)"}
  H -- ok --> J["p2p: mobile+content / smart: template+vars rendered"]
  J --> K["bulk_queue_job()"]
  K --> L["stale credit pre-check (same as gradual)"]
  L --> M["TX: INSERT ellsms_bulk_jobs + items"]
  M --> N["redirect back to same page"]
  N --> O["worker: run_bulk_send_pass()<br/>backend.php:574-627<br/>*** NO per-item atomic claim ***<br/>(unlike autoreply's UNIQUE-key claim or schedule's status-guard claim)"]
  O --> P["bulk_send_one_item() -> dispatch_message()"]
```

## Legacy url_send.html

```mermaid
flowchart TD
  A["GET/POST /sms/url_send.html<br/>creds via $_REQUEST — GET form advertised in docblock"] --> B{"any param empty?"}
  B -- no --> D["SELECT user_ by username"]
  D --> E{"backend_verify_password() fails?"}
  E -- yes --> F["usleep(400000); error_code=-2<br/>*** no rate limit, no lockout ***"]
  E -- no --> G["SELECT ellsms_meta panel_access"]
  G --> H{"no panel_access?"}
  H -- yes --> I["error_code=-3<br/>*** distinguishable from -2: brute-force oracle ***"]
  H -- no --> J["normalize_msisdn(destination)"]
  J --> M["dispatch_message()"]
  M --> N{"ok?"}
  N -- no --> O["error_code=-5 if msg contains a specific Persian string else -6<br/>*** string-matched, fragile to message wording changes ***"]
  N -- yes --> Q["reference_id = random_int() (confirmed non-sequential)"]
  Q --> R["audit() logs real outbound id + reference"]
  R --> S["respond JSON"]
```

External dependencies: `app/backend.php` (dispatch_message, bulk_queue_job, bulk_send_one_item, run_bulk_send_pass), `app/bootstrap.php` (normalize_msisdn, normalize_originator, parse_destinations, sms_parts, filter_blacklist), `app/xlsx_reader.php`.
