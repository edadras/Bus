# اپلیکیشن‌های موبایل (Flutter)

سه اپلیکیشن نیتیو، همگی بر پایه یک بسته مشترک:

| مسیر | اپلیکیشن | مخاطب |
|---|---|---|
| `packages/hamsafar_core` | بسته مشترک | — |
| `apps/passenger` | همسفر | مسافر |
| `apps/driver` | همسفر راننده | راننده |
| `apps/merchant` | همسفر پذیرنده | پذیرنده |

## چرا بسته مشترک؟

کلاینت API، مدل‌های داده، دیزاین سیستم، مدیریت توکن و لایه Real-Time دقیقاً یک
پیاده‌سازی دارند. هر سه اپ همان `/api/v1` را صدا می‌زنند که وب‌اپ و پنل مدیریت
استفاده می‌کنند؛ هیچ اپی مسیر ویژه یا دسترسی خاصی ندارد.

## اجرا

```bash
cd packages/hamsafar_core && flutter pub get
cd ../../apps/passenger && flutter pub get

# نشانی سرور در زمان اجرا تزریق می‌شود (پیش‌فرض: http://10.0.2.2:8000 برای شبیه‌ساز اندروید)
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

## ساخت خروجی

```bash
flutter build apk   --release --dart-define=API_BASE_URL=https://api.example.com
flutter build ipa   --release --dart-define=API_BASE_URL=https://api.example.com
```

## نکات مهم

- زبان پیش‌فرض فارسی و جهت رابط RTL است (`Directionality.rtl`).
- اعداد با ارقام فارسی نمایش داده می‌شوند.
- توکن احراز هویت در `flutter_secure_storage` نگهداری می‌شود، نه در `SharedPreferences`.
- اپ راننده برای ارسال موقعیت در پس‌زمینه به مجوز `ACCESS_BACKGROUND_LOCATION` نیاز دارد.
