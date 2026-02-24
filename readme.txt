=== نرخ چند؟ ===
Contributors: AliFirouzi
Donate link: https://AtomSoft.ir/
Tags: currency, gold, shortcode, elementor, rtl
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

افزونه فارسی و حرفه ای نمایش نرخ ارز و طلا با چند منبع، پنل RTL و ویجت های Elementor.
این افزونه به صورت ویژه مناسب سایت های ایرانی است و برای عملکرد بهتر، نصب روی سرور داخل ایران توصیه می شود.

توسعه دهنده: علی فیروزی - اتم سافت (با همکاری OpenAI)
وبسایت: https://AtomSoft.ir/

== امکانات ==

- منوی مستقل پیشخوان با نام `نرخ چند؟`
- داشبورد کارت محور و جدول مدیریتی مدرن
- مدیریت چند منبع (ICE, Milli, CBI, HTML)
- فعال/غیرفعال کردن هر منبع
- بازه واکشی جدا برای هر منبع (ثانیه)
- شورت کد چندحالته: جدول، کارت، تیکر
- ویجت های Elementor: جدول، کارت ها، تیکر

== شورت کد ==

- `[exchange_rate]`
- `[exchange_rate source="ice_havaleh" view="table"]`
- `[exchange_rate source="ice_havaleh" view="cards" symbols="USD,EUR"]`
- `[exchange_rate source="ice_usd_history" view="table" limit="20"]`
- `[exchange_rate source="milli_price18" view="cards" title="طلای 18 عیار"]`
- `[exchange_rate source="cbi_havaleh" view="ticker"]`

پارامترها:
- `source`: کلید منبع
- `symbols`: کد ارزها با کاما
- `date`: `latest` یا `YYYY-MM-DD`
- `limit`: تعداد خروجی (0 = همه)
- `title`: عنوان نمایش
- `view`: `table` یا `cards` یا `ticker`

== المنتور ==

دسته جدید: `نرخ چند؟`

ویجت ها:
- `نرخ چند؟ - جدول`
- `نرخ چند؟ - کارت ها`
- `نرخ چند؟ - تیکر`

== CBI محافظت شده ==

برای CBI:
1. نوع منبع را `CBI محافظت‌شده (TSPD)` بگذارید.
2. URL endpoint را وارد کنید.
3. هدرهای لازم را در `هدر خام` وارد کنید (مثل Cookie و X-Security-CSRF-Token و ...).

== نصب ==

1. پوشه افزونه را در `/wp-content/plugins/` قرار دهید.
2. افزونه را فعال کنید.
3. از منوی `نرخ چند؟` منابع را تنظیم کنید.
4. واکشی دستی بزنید و شورت کد/ویجت را استفاده کنید.

== توسعه برای گیت‌هاب ==

- راهنمای توسعه در فایل `DEVELOPER_GUIDE.md` قرار دارد.
- این فایل برای توسعه دهنده های جدید نوشته شده و تمام بخش های اصلی افزونه را توضیح می دهد.

== Changelog ==

= 1.3.1 =
* Scope کامل CSS فرانت با ریشه اختصاصی برای جلوگیری از تداخل با قالب/افزونه ها
* اصلاح فایل ویجت Section المنتور (مشکل انکودینگ)
* بهبود پایداری ثبت ویجت ها تا خرابی یک ویجت باعث اختلال کل Elementor نشود

= 1.3.0 =
* بازطراحی کامل UI/UX در پنل و فرانت (RTL)
* اضافه شدن نمایش های table/cards/ticker
* اضافه شدن ادغام Elementor با 3 ویجت
* فارسی سازی کامل نام ها و منوها

= 1.2.1 =
* پشتیبانی CBI TSPD و هدرهای سفارشی

= 1.2.0 =
* منبع میلی و کمینه/بیشینه روزانه
