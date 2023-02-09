<?php

/***** CODE SAMPLE: A system to bring OS-level cron jobs in-house *****/

/*

crontab:  * * * * * . /etc/profile.d/sh.local; /usr/bin/php -f /var/www/html/admin/jobs/cron.php

Year is always set to -1 (any).  We've never specified cron job years.  However, you can directly enter a year in the DB to force-enable it.

Month, day, weekday, hour, and minute are bitwise comparisons, so each bit represents an entry:
    Day and month start at 1, so 0b1 means the 1st (or January), and 0b1001011 means the 1st, 2nd, 4th, 7th (or Jan, Feb, Apr, Jul).
    Weekday, hour and minute start at 0, so 0b1 means midnight (or :00, or Sunday), 0b10110 means 1am, 2am, 4am (or :01, :02, :04).

Sorry this is so confusing, but that's just how the logic breaks down.  Efficiency outweighs clarity in this realm.

*/

include __DIR__ . "/../../include/framework.php";

if(!config('site_DbLink')->isConnected())
    die('No database connection.');

$internal_offset = 0;  // in minutes - can be used to offset entire cron list, so set to -300 for a -5 hour offset, etc

list($year, $month, $day, $weekday, $hour, $minute) = explode(' ', date('Y m d w H i', time() + ($internal_offset * 60)));

foreach(Cron::select()->where('enabled = 1 AND (year = -1 OR year = ?) AND (month = -1 OR month & (1 << ?)) AND (day = -1 OR day & (1 << ?)) AND (weekday = -1 OR weekday & (1 << ?)) AND (hour = -1 OR hour & (1 << ?)) AND (minute = -1 OR minute & (1 << ?))', [ $year, $month - 1, $day - 1, $weekday, $hour, $minute ])->getAll() as $job) {
    $job->execute();
}

/*

DROP TABLE IF EXISTS `cron`;
CREATE TABLE `cron` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` TEXT NOT NULL,
  `day` BIGINT DEFAULT -1,
  `month` BIGINT DEFAULT -1,
  `year` BIGINT DEFAULT -1,
  `weekday` BIGINT DEFAULT -1,
  `hour` BIGINT DEFAULT 0,
  `minute` BIGINT DEFAULT 0,
  `enabled` BOOLEAN NOT NULL DEFAULT 1,
  `deleted` BOOLEAN NOT NULL DEFAULT 0,
  `created_date` DATETIME NOT NULL DEFAULT NOW(),
  `created_by` INT NOT NULL DEFAULT 0,
  `modified_date` DATETIME NOT NULL DEFAULT NOW(),
  `modified_by` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
);

-- reports
INSERT INTO cron (name, hour, minute) VALUES ('report_sales_estimate', 1 << 21, 1 << 0);                       -- 0 21 * * MON-FRI      -- 1 << 0 is 1, but noted as such for clarity and consistency
INSERT INTO cron (name, hour, minute) VALUES ('report_sales', 1 << 1, 1 << 0);                          -- 0 1 * * *
INSERT INTO cron (name, hour, minute) VALUES ('report_??_daily', 1 << 7, 1 << 57);                   -- 57 7 * * *
INSERT INTO cron (name, hour, minute, day) VALUES ('report_??_monthly', 1 << 5, 1 << 35, 1 << 0);     -- 35 5 1 * *
INSERT INTO cron (name, hour, minute, weekday) VALUES ('report_??_tasks_weekly', 1 << 7, 1 << 0, 0b10);    -- 0 7 * * MON
INSERT INTO cron (name, hour, minute, weekday) VALUES ('report_??_offsite_weekly', 1 << 7, 1 << 5, 0b10);  -- 5 7 * * MON
INSERT INTO cron (name, hour, minute) VALUES ('report_??_daily', 1 << 23, 1 << 0);                     -- 0 23 * * *
INSERT INTO cron (name, hour, minute, weekday) VALUES ('report_??_reschedule', 1 << 1, 1 << 35, 0b100);    -- 35 1 * * 1
INSERT INTO cron (name, hour, minute, weekday) VALUES ('report_??_reschedule', 1 << 1, 1 << 45, 0b10000);  -- 45 1 * * 4

-- portal
INSERT INTO cron (name, hour, minute) VALUES ('update_search_index', -1, 0b100000000010000000001000000000100000000010000000001);  -- * /10 * * * *
INSERT INTO cron (name, hour, minute) VALUES ('send_wo_reminders', -1, 0b10000000000000010000000000000010000000000000010000000000);  -- 10,25,40,55 * * * *
INSERT INTO cron (name, hour, minute, weekday) VALUES ('inactivity_hold', 0b1, 0b1, 0b1);                     -- 0 0 * * SUN
INSERT INTO cron (name, hour, minute) VALUES ('reconsider_inreview', 1 << 0, 1 << 7);                   -- 7 0 * * *
INSERT INTO cron (name, hour, minute) VALUES ('update_wo_status', 1 << 0, 1 << 6);                        -- 6 0 * * *
INSERT INTO cron (name, hour, minute) VALUES ('autoescalate', 1 << 4, 1 << 6);                          -- 6 4 * * *
INSERT INTO cron (name, hour, minute) VALUES ('notify_installers', 1 << 18, 1 << 0);                     -- 1 18 * * *
INSERT INTO cron (name, hour, minute, weekday) VALUES ('installer_not_complete', 1 << 5, 1 << 9, 1 << 0); -- 9 5 * * 1
INSERT INTO cron (name, hour, minute, weekday) VALUES ('record_ratings', 0b1111110, 0b10010010010010010010010010010010010010010010010010010010010, 0b10);  -- * /3 1-6 * * MON

-- system
INSERT INTO cron (name, hour, minute) VALUES ('email_queue', -1, 0b10101010101010101010101010101010101010101010101010101010101);  -- * /2 * * * *
INSERT INTO cron (name, hour, minute) VALUES ('webhooks', -1, 0b10010010010010010010010010010010010010010010010010010010010);  -- * /3 * * * *
INSERT INTO cron (name, hour, minute) VALUES ('data_import', 0b10000000000010000, 1 << 8);            -- 8 4,16 * * *
INSERT INTO cron (name, hour, minute) VALUES ('clean_files', 1 << 0, 1 << 0);                            -- 0 0 * * *
INSERT INTO cron (name, hour, minute) VALUES ('update_geocode', 1 << 18, 1 << 44);                       -- 44 18 * * *
INSERT INTO cron (name, hour, minute) VALUES ('update_tracking', -1, 1 << 37);                          -- 37 * * * *
INSERT INTO cron (name, hour, minute, day) VALUES ('clear_integration_logs', 1 << 4, 1 << 5, 1 << 0);   -- 5 4 1 * *

*/
