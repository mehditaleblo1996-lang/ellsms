# Voice messages (ZarinCall)

Customers can send recorded or generated voice messages by phone call, interactive calls that answer
a key press with an SMS, and one-time codes read out by phone. This is the panel's counterpart of the
old platform's voice service (Vesal's `VoiceRest` / `VoiceJob` / `VoiceBulkJob` / CDR report). Calls go
out through [ZarinCall](https://www.zarincall.ir/) instead of an in-house Asterisk server.

Code: `app/Voice/ZarinCall.php` (provider client), `app/VoiceMessages.php` (files, sends, money,
worker), `public/voice.php`, `public/voice-files.php`, `public/voice-settings.php`,
`app/Api/Handlers/Voice.php`, `cron/voice-worker.php`. Schema: `db/migrations/2026_10_23_voice_messages.sql`.

## Setup (platform admin)

1. `SMS_GATEWAY_MASTER_KEY` (≥ 32 characters) must be set. The ZarinCall API key is stored encrypted
   with a key derived from it (AES-256-GCM, its own HKDF purpose label). Changing the master key makes
   the stored API key unreadable, and the settings page then asks for it again.
2. Apply the migration (`make db-migrations-apply`, or `deploy.sh`).
3. Open **/admin/voice** (سرویس پیام صوتی) and set:
   - **Enabled**, the **service URL** (default `https://ws.zarincall.ir/Apiv2`) and the **API key** from
     the ZarinCall panel. The key is write-only and never shown again.
   - **Price per pulse** (Rial, per recipient). Sending stays closed until this is set.
   - **OTP price** (Rial). Voice OTP stays closed until this is set.
   - Optional: extra price per recipient for interactive sends, price per text-to-speech, pulse
     length (30 s, Vesal's unit), the text-to-speech speakers (one `value=name` per line: the value is
     ZarinCall's `gender`, which its documentation does not name, so set them after trying), batch size, recipient limit, polling interval, settlement deadline,
     how long ZarinCall keeps generated speech, and the minimum interval between two OTP calls to one
     number.
4. **Ready statuses.** ZarinCall checks every new file before it can be sent. Until then it answers
   «فایل صوتی آماده به ارسال نیست» ("voice file is not ready to send"), and it does not document its
   `VoiceStatus` codes. The files page shows each file's code. Once a file has gone out successfully,
   enter its code in **voice_ready_statuses** (comma-separated). From then on, a file with another code
   is refused before any call is made (`voice_file_not_approved`), and the worker re-reads it every
   five minutes for up to seven days until it is ready. Left empty, ZarinCall alone decides.
5. **Check the connection** on the same page. It reads the ZarinCall account balance (`User/GetPulse`).

The menus «پیام صوتی» and «فایل‌های صوتی» appear for customers once the service is ready. Menu
roles (`voice`, `voice_files`) can close them per user, like any other menu.

## What a customer can do

| | Where | ZarinCall call |
|---|---|---|
| Upload a WAV/MP3 (≤ 10 MB) | /messages/voice/files | `Voice/UploadBase64`, then `Voice/Get` for the duration |
| Generate speech from Persian text (≤ 2000 chars, a speaker the admin configured) | /messages/voice/files, `POST /api/v1/voice/tts` | `Voice/TextToVoice` |
| Listen to a file, delete it | /messages/voice/files | `Voice/Download`, `Voice/Delete` |
| Send a file to numbers (typed, a .txt/.csv file, or a contact group), now or at a chosen time | /messages/voice, `POST /api/v1/voice/messages` | `Message/Send` |
| Interactive send: a recipient who presses digit *d* is sent an SMS | same, `type: ivc` | `Message/SendIVC` |
| Voice OTP, optionally after an announcement file | /messages/voice, `POST /api/v1/voice/otp` | `Message/SendOTP` / `SendOTP2` |
| Follow a send: answered / not answered calls, cost, per-batch detail; cancel a scheduled send | /messages/voice, `GET /api/v1/voice/messages/{id}` | `Message/Get` |

Customers see their organization's files and sends (or only their own, without an organization).
Platform admins see everything and send free of charge, like other channels. Blacklisted numbers are
skipped (an OTP is not filtered, because the recipient asked for it). Prohibited words apply to
generated speech and to the interactive SMS text. Support (impersonation) sessions cannot send.

## Money

Everything is charged in Rial from the customer's wallet, like SMS.

- **Pulses:** `pulses = max(1, ceil(duration / pulse length))`. A 45-second file is 2 pulses.
- **Price of one recipient:** `voice` = pulses × price per pulse; `ivc` = that + the interactive
  extra; `otp` = OTP price; `otp2` = OTP price + the announcement's pulses × price per pulse.
- **When a send is accepted,** the worst case (price × recipients) is *reserved*
  (`ellsms_wallet_reservations`, reference `voice_message:<id>`). The customer's available credit drops
  at once.
- **When ZarinCall's report for a batch is final** (`RecSuccess + RecFailed ≥ RecCount`), only the
  answered calls (`RecSuccess`) are charged.
- **When every batch is settled,** the rest of the reservation is released back to the wallet. The
  send becomes `completed`.
- If a batch never gets a final report, it is settled after **voice_settle_after_hours** (48 h by
  default). Every call that ZarinCall did not report as failed is charged.
- A send ZarinCall refuses outright, a cancelled scheduled send, and a send whose service was switched
  off before its time all release the whole reservation.
- An OTP that ZarinCall accepts without a `MsgID` cannot be followed, so it is charged on acceptance.

## How sending works

- A send is split into **batches** of `voice_batch_size` numbers. Each batch is one provider call with
  its own `MsgID` (`ellsms_voice_message_batches`).
- A batch is marked `sending` **before** its call. If the process dies mid-call, the worker records
  that batch as failed (`interrupted`) and does not charge it. It never re-sends it, because a
  recipient must not be called twice. Batches that had not started yet are sent normally.
- Immediate sends are dispatched inside the request. Scheduled sends, recovery, progress polling
  (every `voice_poll_interval_seconds`) and settlement run in **`cron/voice-worker.php`**: the
  scheduler's `voice-worker` job runs it every minute, and a MySQL named lock allows only one pass at
  a time.
- Scheduled times are in the server's local time (Asia/Tehran), at most 90 days ahead. The API accepts
  ISO 8601 with an offset.
- An OTP code is passed straight to ZarinCall and **never stored**.

Statuses: `scheduled` → `sending` → `sent` (calls in progress) → `completed`; or `failed` / `cancelled`.

## The ZarinCall API

The PDF guide only points at `ws.zarincall.ir/code`, `/test` and `/json`. The contract below follows
ZarinCall's own client library (the `ZarinCall` NuGet package, 1.7.x):

```
POST https://ws.zarincall.ir/Apiv2/{Controller}/{Action}
Authorization: basic apikey:<API key>
Content-Type: application/json
→ {"R_Success": true, "R_Code": 0, "R_Error": null, "R_Message": "…", …}
```

| Action | Body | Answer |
|---|---|---|
| `Message/Send` | `{voiceId, numbers[]}` | `MsgID` |
| `Message/SendIVC` | `{voiceId, numbers[], digit, sms}` | `MsgID` |
| `Message/SendOTP` | `{code, number}` | (`MsgID`) |
| `Message/SendOTP2` | `{code, number, voiceId}` | (`MsgID`) |
| `Message/Get` | `{msgId}` | `Data {RecCount, RecSuccess, RecFailed, SendStatus, SendDes, …}` |
| `Voice/UploadBase64` | `{fileName, fileByteBase64}` | `VoiceID` |
| `Voice/TextToVoice` | `{title, gender, text, saveDays}` | `VoiceID`, `VoiceBase64` |
| `Voice/Get` | `{voiceId}` | `Data {Duration, VoiceStatus, Title, MediaTypeName, DataSize, …}` |
| `Voice/Download` / `Voice/Delete` | `{voiceId}` | `FileByteBase64` / — |
| `User/GetPulse` | `{}` | `Pulse` |

Numbers are sent as `09xxxxxxxxx`. Answer fields are read case-insensitively. Every call goes through
the same SSRF guard as SMS gateways: HTTPS and public addresses in production.

## Operations

```
php cron/voice-worker.php            # one pass by hand (safe next to the scheduler)
make scheduler-run JOB=voice-worker
```

Metrics: `voice.provider.calls{action,result}`, `voice.provider.duration{action}`,
`voice.message.created{kind,source}`, `voice.message.completed`, `voice.message.failed`,
`voice.batch.sent|failed{kind}`, `voice.file.created{source}`. Logs: `voice.*`. Audit:
`voice.settings`, `voice.file.*`, `voice.message.create|cancel`.

Staging (`ops/staging/sanitize.sql`) switches the service off and removes the production API key.

## Tests

- `tests/Integration/VoiceMessagesTest.php`: the service against a ZarinCall-shaped API
  (`tests/fixtures/fake_zarincall_server.php`). Covers request shapes, the encrypted key, pricing,
  reserve/charge/release, scheduling and cancel, refusal, recovery, OTP, TTS and visibility.
- `tests/Integration/VoiceHttpTest.php`: the panel pages and `/api/v1/voice/*` over real HTTP,
  including scopes.
- `tests/Unit/VoiceHelpersTest.php`: pulses, WAV duration, number format, answer parsing.
