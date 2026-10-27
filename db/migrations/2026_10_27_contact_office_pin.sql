-- Office map pin (ellsms_settings: contact_map_lat, contact_map_lng).
--
-- With a pin, /contact shows an OpenStreetMap map with the office marked. Google could not find
-- the building from its address, so this is an APPROXIMATE point on خیابان جمهوری اسلامی, Tehran,
-- between جمالزاده and کارگر (near plak 1120). Replace it with the exact point under
-- «تنظیمات ← تماس با ما». Written only where the keys are missing or empty, so a pin an admin has
-- already entered is kept and re-running this file changes nothing.
SET NAMES utf8mb4;

INSERT INTO ellsms_settings (skey, svalue)
VALUES ('contact_map_lat', '35.69622'),
       ('contact_map_lng', '51.39051')
ON DUPLICATE KEY UPDATE svalue = IF(svalue = '', VALUES(svalue), svalue);
