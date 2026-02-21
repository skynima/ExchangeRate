# راهنمای توسعه افزونه نرخ چند؟

این سند برای توسعه دهنده هایی است که می خواهند افزونه را روی گیت هاب ادامه دهند.

## معرفی کوتاه

`نرخ چند؟` یک افزونه وردپرس برای:

- واکشی نرخ ارز و طلا از چند منبع
- ذخیره داده در دیتابیس با ساختار یکتا برای هر روز/منبع
- نمایش داده با شورت کد و ویجت المنتور
- پنل مدیریت فارسی (RTL) با واکشی دستی و خودکار

منابع پیش فرض فعلی:

- ICE (نرخ حواله)
- ICE (تاریخچه دلار)
- میلی (قیمت هر گرم طلای 18 عیار)
- CBI (حواله)

## اطلاعات پروژه

- نام افزونه: `نرخ چند؟`
- متن دامنه: `exchange-rate`
- فایل بوت: `exchange-rate.php`
- کلاس اصلی: `includes/class-exchange-rate.php`
- لایه API و امنیت: `includes/class-exchange-rate-api.php`

## ساختار پوشه ها

- `exchange-rate.php`: هدر افزونه، بارگذاری کلاس ها، هوک فعال/غیرفعال
- `includes/class-exchange-rate.php`: منطق اصلی، پنل مدیریت، شورت کد، کران، ذخیره داده
- `includes/class-exchange-rate-api.php`: درخواست HTTP، پارس داده هر منبع، اعتبارسنجی و امنیت URL
- `includes/class-nerkhchand-elementor.php`: ثبت دسته و ویجت های Elementor
- `includes/elementor/widgets/`: ویجت های المنتور
- `assets/css/`: استایل فرانت و ادمین
- `assets/js/`: اسکریپت های UX و واکشی زنده
- `docs/cloudflare-worker.js`: نمونه Worker برای Relay
- `readme.txt`: توضیحات نصب/ویژگی برای WordPress

## دیتابیس

جدول اصلی:

- `{$wpdb->prefix}exchange_rate_daily_snapshots`

هدف:

- هر منبع برای هر `date_key` فقط یک ردیف داشته باشد (upsert)
- در همان روز با واکشی مجدد، ردیف موجود به‌روزرسانی شود

ستون های مهم:

- `source_key`
- `date_key`
- `source_date`
- `rows_json`
- `rows_count`
- `fetched_at`

ایندکس مهم:

- `UNIQUE KEY uniq_source_date (source_key, date_key)`

## چرخه واکشی داده

1. منابع از option خوانده می شوند (`exchange_rate_sources`)
2. بر اساس interval هر منبع، واکشی خودکار انجام می شود
3. پاسخ API/HTML پارس می شود
4. snapshot نهایی در جدول ذخیره می شود
5. خطاها در option `exchange_rate_last_errors` ثبت می شوند

## امنیت

چند لایه امنیتی در کد فعال است:

- جلوگیری از SSRF برای URL های خصوصی/لوکال
- ماسک شدن URL منابع سیستمی در UI
- رمز/محافظت فیلدهای حساس منابع سیستمی در تنظیمات
- nonce و capability check برای اکشن های ادمین
- قفل سمت سرور برای منابع سیستمی

قفل منابع سیستمی:

- منابع سیستمی قابل حذف نیستند
- فقط `interval_seconds` و فیلدهای توضیحی قابل ویرایش هستند
- فیلدهای حساس مثل URL/headers/type/enabled قفل هستند

## شورت کدها

شورت کد اصلی:

- `[exchange_rate]`
- `[exchange_rate source="ice_havaleh"]`

پارامترها:

- `source`
- `symbols`
- `date`
- `limit`
- `title`
- `subtitle`
- `view` (`table|cards|ticker`)
- `section` (`full|title|description|source_meta|fetch_date|table_only`)

## Elementor

دسته اختصاصی: `نرخ چند؟`

ویجت های فعلی:

- جدول
- کارت ها
- تیکر
- بخش خروجی (section-based)

## اضافه کردن منبع جدید

برای اضافه کردن source type جدید:

1. در `class-exchange-rate-api.php` parser جدید اضافه کنید
2. type جدید را در فرم مدیریت منابع معرفی کنید
3. mapping ستون های خروجی را با ساختار table/cards هماهنگ کنید
4. پیام خطای واضح برای parse failure برگردانید
5. تست دستی در هاست داخلی/خارجی انجام دهید

## Relay برای هاست خارجی

اگر هاست به API مبدا دسترسی ندارد:

- از Cloudflare Worker یا Proxy امن استفاده کنید
- نمونه اولیه در `docs/cloudflare-worker.js` موجود است
- URL رله را در منبع ثبت کنید

## انتشار عمومی در GitHub

پیشنهاد:

1. یک `README.md` انگلیسی/فارسی مختصر برای صفحه اصلی repo بگذارید
2. `readme.txt` را برای WordPress نگه دارید
3. یک `CHANGELOG` منظم داشته باشید
4. نسخه را در `exchange-rate.php` و `readme.txt` همزمان بالا ببرید
5. قبل از release این موارد تست شوند:
   - ذخیره/ویرایش/قفل منابع
   - واکشی دستی و خودکار
   - شورت کدها
   - ویجت های المنتور
   - RTL در موبایل و دسکتاپ

## مالکیت و توسعه

- توسعه دهنده: علی فیروزی
- برند: اتم سافت
- همکاری فنی: OpenAI
- وبسایت: https://atomsoft.ir/

