-- ELLSMS — landing-page hero videos (replaces the old image slider).
--
-- video is a filename under public/assets/video/landing/, poster an optional
-- filename under public/assets/img/landing-video-posters/ — both served
-- directly, same public-asset trust level as ellsms_slides.image. The
-- landing page shows the two lowest sort_order active rows side by side;
-- see public/landing-videos.php for the admin CRUD.
CREATE TABLE IF NOT EXISTS ellsms_landing_videos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(190) NOT NULL,
  body       TEXT NULL,
  video      VARCHAR(190) NOT NULL,
  poster     VARCHAR(190) NULL,
  link_url   VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
