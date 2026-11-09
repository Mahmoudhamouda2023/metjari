# تحقق متجري 1.6.0

- PHP/SQLite: 153 اختبارًا، 1051 تحققًا، دون أخطاء أو إخفاقات.
- JavaScript: 16 اختبارًا ناجحًا.
- فحص صياغة 147 ملف PHP و86 قالبًا مجمعًا: دون أخطاء.
- جرى بناء CSS من المصدر، وفحص صياغة JavaScript.
- لا توجد تجربة MySQL أو تحقق بصري بمتصفح رسومي في هذه البيئة.

## إعادة الفحص على نسخة اختبار منفصلة

```sh
php vendor/bin/phpunit -c phpunit.sqlite.xml
node --test tests/finance.test.cjs tests/notifications.test.cjs
php artisan view:cache
```

ملف phpunit.sqlite.xml يفرض SQLite في الذاكرة؛ لا تستخدم إعداد اختبارات يشير إلى قاعدة العمل الحقيقية.

تفاصيل التشغيل والترقية: START_HERE_1_6_0_AR.md.
