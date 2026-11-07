UPDATE `settings` SET
  `site_phone` = IF(COALESCE(`site_phone`,'')='', '(31) 97147-2258', `site_phone`),
  `site_whatsapp` = IF(COALESCE(`site_whatsapp`,'')='', '5531971472258', `site_whatsapp`),
  `social_fb_page` = IF(COALESCE(`social_fb_page`,'')='', 'https://www.facebook.com/connectcondominios', `social_fb_page`),
  `social_instagram_page` = IF(COALESCE(`social_instagram_page`,'')='', 'https://www.instagram.com/connectcondominios', `social_instagram_page`),
  `social_youtube_page` = IF(COALESCE(`social_youtube_page`,'')='', 'https://www.youtube.com/connectcondominios', `social_youtube_page`),
  `social_linkedin_page` = IF(COALESCE(`social_linkedin_page`,'')='', 'https://www.linkedin.com/page/connectcondominios', `social_linkedin_page`)
WHERE `id` = 1;
