-- ============================================================================
-- HamiHubX.ir - Migration 002: داده‌های اولیه استان گیلان (مرحله S1)
-- شامل: شهرهای اصلی گیلان، دسته‌بندی‌ها، بسته‌های دسته (Category Packs) و رکوردهای نمونه
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+03:30';

-- ۱. درج شهرهای پایه استان گیلان با مختصات و محدوده جغرافیایی (bbox_json)
INSERT IGNORE INTO `cities` (`id`, `name_fa`, `slug`, `lat`, `lng`, `bbox_json`, `publish_mode`, `is_active`) VALUES
    (1, 'رشت', 'rasht', 37.2808340, 49.5830570, '[37.1900,49.4800,37.3600,49.6800]', 'manual', 1),
    (2, 'لاهیجان', 'lahijan', 37.2070850, 50.0038680, '[37.1600,49.9500,37.2500,50.0600]', 'manual', 1),
    (3, 'بندر انزلی', 'bandar-anzali', 37.4727250, 49.4622230, '[37.4300,49.3600,37.5100,49.5400]', 'manual', 1),
    (4, 'فومن', 'fuman', 37.2238890, 49.3125000, '[37.1800,49.2700,37.2600,49.3500]', 'manual', 1),
    (5, 'ماسال', 'masal', 37.3630560, 49.1327780, '[37.3200,49.0800,37.4000,49.1800]', 'manual', 1);

-- ۲. درج محله‌های نمونه شهر رشت و لاهیجان
INSERT IGNORE INTO `neighborhoods` (`id`, `city_id`, `name_fa`, `slug`, `lat`, `lng`) VALUES
    (1, 1, 'گلسار', 'golsar', 37.3025000, 49.5742000),
    (2, 1, 'منظریه', 'manzarieh', 37.2634000, 49.5891000),
    (3, 1, 'خیابان معلم', 'moallem', 37.2842000, 49.5778000),
    (4, 1, 'سبزه میدان', 'sabzeh-meydan', 37.2789000, 49.5806000),
    (5, 2, 'شیطان کوه (تله‌کابین)', 'sheytan-kooh', 37.2015000, 50.0192000),
    (6, 2, 'استخر لاهیجان', 'estakhr-lahijan', 37.2031000, 50.0114000);

-- ۳. درج ۵ دسته‌بندی اصلی با آیکون پیش‌فرض SVG سبک (بخش ۱۳ سیاست عکس)
INSERT IGNORE INTO `categories` (`id`, `parent_id`, `name_fa`, `slug`, `default_icon_svg`, `sort`, `is_active`) VALUES
    (1, NULL, 'کافه و رستوران', 'cafe-restaurant', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>', 1, 1),
    (2, NULL, 'سالن زیبایی', 'beauty-salon', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>', 2, 1),
    (3, NULL, 'کلینیک و دندانپزشکی', 'clinic-dental', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2v20M2 12h20"/><rect x="4" y="4" width="16" height="16" rx="3"/></svg>', 3, 1),
    (4, NULL, 'ویلا و اقامتگاه', 'villa-ecolodge', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>', 4, 1),
    (5, NULL, 'جاذبه گردشگری', 'tourism-attraction', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/></svg>', 5, 1);

-- ۴. درج ۵ بسته دسته آماده (Category Packs) با کوئری‌های جستجوی استاندارد هر پرووایدر
INSERT IGNORE INTO `category_packs` (`id`, `name`, `queries_json`, `is_active`) VALUES
    (1, 'بسته کافه‌ها', '{"osm":["amenity=cafe"],"neshan":["کافه"]}', 1),
    (2, 'بسته سالن‌های زیبایی و مراقبت پوست', '{"gosom":["سالن زیبایی","آرایشگاه زنانه"],"osm":["shop=beauty","shop=hairdresser"],"neshan":["سالن زیبایی"]}', 1),
    (3, 'بسته کلینیک زیبایی و دندانپزشکی', '{"gosom":["کلینیک دندانپزشکی","دندانپزشک","کلینیک زیبایی"],"osm":["amenity=dentist","amenity=clinic"],"neshan":["دندانپزشکی","کلینیک"]}', 1),
    (4, 'بسته ویلا، هتل و اقامتگاه بومگردی', '{"gosom":["اقامتگاه بومگردی","هتل","اجاره ویلا"],"osm":["tourism=hotel","tourism=guest_house"],"neshan":["اقامتگاه بومگردی","هتل"]}', 1),
    (5, 'بسته جاذبه‌های طبیعی و تاریخی', '{"gosom":["جاذبه دیدنی","پارک جنگلی","موزه"],"osm":["tourism=attraction","historic=monument"],"neshan":["جاذبه گردشگری"]}', 1);

INSERT IGNORE INTO `category_pack_items` (`id`, `pack_id`, `category_id`, `queries_json`) VALUES
    (1, 1, 1, '{"keywords":["کافه"]}'),
    (2, 2, 2, '{"keywords":["سالن زیبایی"]}'),
    (3, 3, 3, '{"keywords":["دندانپزشکی","کلینیک"]}'),
    (4, 4, 4, '{"keywords":["اقامتگاه بومگردی","ویلا"]}'),
    (5, 5, 5, '{"keywords":["جاذبه گردشگری"]}');
