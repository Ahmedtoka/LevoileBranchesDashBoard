# نشر تحديثات LeVoile على السيرفر اللايف (stores.levoilestores.com)

دليل خطوة بخطوة لرفع كل التحديثات (باك-إند + شيك ليستات + الموبايل).

---

## 0) قبل ما تبدأ — خد Backup
من Cloudways: اعمل **Backup** للـ Application + الـ Database (زرار Backup Now).
ده مهم لأن سيدر الشيك ليست بيعيد بناء أسئلة قالبَي مدير الفرع/الأريا مانجر.

---

## 1) ارفع كود الباك-إند

### الطريقة (أ) — Git (الأفضل)
لو المشروع على Git:
```bash
# على جهازك (داخل BranchesDashboard)
git add -A
git commit -m "Live update: tickets cycle, dept manager, profile, real checklists"
git push
```
وبعدين على السيرفر:
```bash
cd /home/master/applications/<APP>/public_html   # مسار تطبيقك على Cloudways
git pull
```

### الطريقة (ب) — SFTP (لو مش بتستخدم Git)
ارفع المجلدات/الملفات دي من `BranchesDashboard` للسيرفر (طبق فوق القديم):
- `app/`  (Controllers, Models, Services)
- `routes/api.php`
- `database/migrations/2026_06_24_000010_add_avatar_to_users.php`  ← **ملف جديد**
- `database/seeders/`  (DepartmentSeeder, RealChecklistSeeder ← جديد, DatabaseSeeder)
- `database/data/checklist_store_manager.json`  ← **جديد ومهم**
- `database/data/checklist_area_manager.json`   ← **جديد ومهم**
- `resources/views/dashboard/`  (maintenance, users, coverage)

> **متترفعش:** `vendor/` ، `.env` ، `storage/` (دول بتوع السيرفر).

---

## 2) أوامر السيرفر (SSH) — بالترتيب
ادخل على SSH للتطبيق ونفّذ:

```bash
cd /home/master/applications/<APP>/public_html

composer install --no-dev --optimize-autoloader     # لو الـ vendor مش موجود/قديم

php artisan migrate --force                          # يضيف عمود avatar للمستخدمين

php artisan storage:link                             # لو مش معمول قبل كده (للصور)

# استيراد الإدارات الجديدة + الشيك ليستات النهائية (مدير الفرع + الأريا مانجر)
php artisan db:seed --class=DepartmentSeeder --force
php artisan db:seed --class=RealChecklistSeeder --force

php artisan optimize:clear                           # يمسح كاش الراوت/الكونفيج/الفيوز
```

تأكيد إن الشيك ليستات اتزرعت صح:
```bash
php artisan tinker --execute="echo \App\Models\VisitTemplate::where('slug','store-manager')->first()->questions()->count().' store Qs / ';echo \App\Models\VisitTemplate::where('slug','area-manager')->first()->questions()->count().' area Qs';"
```
المفروض يطلع: **48 store Qs / 35 area Qs**.

---

## 3) إعدادات الإنتاج
في ملف `.env` على السيرفر اتأكد من:
```
APP_ENV=production
APP_DEBUG=false        # اقفله بعد ما تتأكد إن كله شغّال
APP_URL=https://stores.levoilestores.com
```
وبعد أي تعديل في `.env`:
```bash
php artisan config:clear
```

---

## 4) الموبايل (APK جديد)
التطبيق أصلاً باصص على اللايف، بس عشان تظهر المزايا الجديدة لازم **build جديد**:
```powershell
cd C:\xampp\htdocs\LevoileBranches\BranchesMobileApp
flutter clean
flutter build apk --release
```
الملف الناتج:
`build\app\outputs\flutter-apk\app-release.apk` — ثبّته على التليفونات.

---

## 5) تأكيدات بعد النشر (Smoke test)
- [ ] لوجين **مدير الفرع** → الشيك ليست اليومية فيها الأقسام التسعة (Staff … Administration).
- [ ] جاوب سؤال **خطأ** → اعمل إرسال حالة الفرع → اتفتحت تذكرة (وللأسئلة بإدارتين → تذكرتين).
- [ ] لوجين **مدير الصيانة** → مصفوفة الطلبات (طلبات الفروع/جديدة/مقبولة…/مرفوضة) بتظهر بأرقام.
- [ ] لوجين **الفني** → جديدة/مقبولة/تنفيذ/مؤجلة، يقبل/يرفض، يبدأ العمل (يطلب الموقع)، يصوّر تم الإصلاح.
- [ ] **Schedule** من الداش بورد → اختار قالب "Area Manager Visit" + أريا مانجر + فرع + تاريخ/وقت → تظهر في موبايله.
- [ ] التذكرة الجديدة رقمها **MTN-**، والتايم لاين بالعبارات العربية الصح.

---

## ملاحظات مهمة
- **سيدر الشيك ليست** بيمسح أسئلة قالبَي store-manager/area-manager القديمة ويبني الجديدة. لو فيه زيارات/إجابات قديمة على اللايف هتفقد ربطها بالأسئلة القديمة — مفيش مشكلة وانت بتجهّز، بس خد Backup (خطوة 0).
- الإدارات الجديدة (VM, HR, LP, Finance, Warehouse, Cleaning, Marketing, Designer) لسه **من غير مديرين/فنيين**، فتذاكرها هتفضل مفتوحة لحد ما نعمل لهم حسابات. (نقدر نزوّدهم في خطوة جاية.)
