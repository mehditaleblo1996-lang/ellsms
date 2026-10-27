-- Office address and phone (ellsms_settings: contact_address, contact_phone).
--
-- Fills in the head-office address and phone shown on /contact (with its map), in the public
-- footer and in the provider block of /terms, /privacy and /refund. Both stay editable under
-- «تنظیمات ← تماس با ما». A value an admin has already entered is kept: each key is written only
-- when it is missing or empty, so re-running this file changes nothing.
--
-- The map pin (contact_map_lat / contact_map_lng) is left unset; until an admin enters it, the map
-- searches the address.
SET NAMES utf8mb4;

INSERT INTO ellsms_settings (skey, svalue)
VALUES ('contact_address', 'تهران، خیابان جمهوری، برج آهنگ، پلاک ۱۱۲۰، واحد ۱۲'),
       ('contact_phone',   '۰۲۱-۴۶۰۵۱۲۷۱')
ON DUPLICATE KEY UPDATE svalue = IF(svalue = '', VALUES(svalue), svalue);
