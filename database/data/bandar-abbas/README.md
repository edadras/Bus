# داده‌های شبکه اتوبوس‌رانی بندرعباس

## وضعیت این داده‌ها

فایل‌های این پوشه **داده نمونه (`sample`)** هستند، نه شبکه رسمی اتوبوس‌رانی بندرعباس.

نام مکان‌ها واقعی و مختصات آن‌ها تقریبی است تا سامانه در محیط توسعه و نمایش،
رفتاری نزدیک به واقعیت داشته باشد؛ اما **ترتیب ایستگاه‌ها، مسیرها و شماره خطوط
با شبکه رسمی مطابقت ندارد** و نباید مبنای اطلاع‌رسانی به مسافران قرار گیرد.

به همین دلیل هر رکورد با `provenance = sample` ذخیره می‌شود و تمام کلاینت‌ها
(اپلیکیشن مسافر، پنل مدیریت و صفحه فرود) این برچسب را به کاربر نمایش می‌دهند.
هیچ داده‌ای بدون `provenance = official` به‌عنوان اطلاعات رسمی نمایش داده نمی‌شود.

## جایگزینی با داده رسمی

هنگام دریافت داده رسمی از سازمان اتوبوس‌رانی، کافی است فایل‌ها با همان ساختار
جایگزین و با `--provenance=official` وارد شوند:

```bash
php artisan transit:import stops   storage/app/import/stops.csv    --city=bandar-abbas --provenance=official
php artisan transit:import lines   storage/app/import/lines.csv    --city=bandar-abbas --provenance=official
php artisan transit:import routes  storage/app/import/routes.csv   --city=bandar-abbas --provenance=official
php artisan transit:import geometry storage/app/import/shapes.geojson --city=bandar-abbas --provenance=official
```

پس از ورود مسیرها، فاصله هر ایستگاه از ابتدای مسیر به‌صورت خودکار محاسبه می‌شود
(`transit:routes:recalculate`)؛ بدون این مرحله محاسبه ایستگاه بعدی و ETA معتبر نیست.

## ساختار فایل‌ها

### `stops.csv`
| ستون | الزامی | توضیح |
|---|---|---|
| `code` | بله | شناسه یکتای ایستگاه در شهر |
| `name` | بله | نام فارسی |
| `name_en` | خیر | نام لاتین |
| `lat`, `lng` | بله | مختصات WGS84 |
| `address` | خیر | نشانی |
| `zone_code` | خیر | کد منطقه (برای کرایه ناحیه‌ای) |
| `is_terminal` | خیر | `1` برای پایانه |
| `geofence_radius` | خیر | شعاع تشخیص حضور (متر، پیش‌فرض ۶۰) |

### `lines.csv`
| ستون | الزامی | توضیح |
|---|---|---|
| `code` | بله | شماره خط |
| `name` | بله | نام خط |
| `color` | خیر | رنگ HEX |
| `origin_label`, `destination_label` | خیر | مبدأ و مقصد |
| `typical_duration_minutes`, `headway_minutes` | خیر | زمان سفر و سرفاصله |

### `routes.csv`
| ستون | الزامی | توضیح |
|---|---|---|
| `line_code` | بله | کد خط |
| `direction` | بله | `outbound` / `inbound` / `loop` |
| `sequence` | بله | ترتیب ایستگاه در مسیر |
| `stop_code` | بله | کد ایستگاه |
| `dwell_seconds` | خیر | زمان توقف |

### `shapes.geojson`
`FeatureCollection` از `LineString`ها؛ هر `Feature` باید در `properties` دارای
`line_code` و `direction` باشد. مختصات به ترتیب استاندارد GeoJSON یعنی
`[lng, lat]` نوشته می‌شوند.
