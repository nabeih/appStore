<?php

namespace Database\Seeders;

use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ترحيل البيانات من قاعدة البيانات القديمة (appstoredb) إلى الهيكل الجديد (appstpre_testdb).
 *
 * التشغيل:
 *   php artisan db:seed --class=DataMigrationSeeder
 *
 * المتطلبات:
 *   1) استيراد ملف النسخة القديمة داخل قاعدة بيانات مستقلة (الافتراضي: appstoredb).
 *   2) (اختياري) ضبط متغيرات البيئة: LEGACY_DB_DATABASE / LEGACY_DB_HOST / LEGACY_DB_PORT /
 *      LEGACY_DB_USERNAME / LEGACY_DB_PASSWORD  (القيم الافتراضية تؤخذ من الاتصال الحالي).
 *   3) نسخ مجلد الصور القديم إلى storage/app/public (يبقى المسار products/colors/... كما هو).
 *
 * الجداول التي لا تُمَس عمداً: roles / permissions / permission_role / application_installations /
 * audit_events / sessions / cache / jobs / migrations (هي جداول نظام يولّدها الـ Installer).
 */
class DataMigrationSeeder extends Seeder
{
    /** حجم الدفعة الواحدة عند الإدخال */
    private const CHUNK_SIZE = 200;

    /** معامل تحويل العملة إلى الوحدة الصغرى (قرش/سنت) المستخدمة في الحقول *_minor */
    private const MINOR_UNIT = 100;

    /** قرص التخزين الذي تُحفظ عليه صور المنتجات في النظام الجديد */
    private const IMAGE_DISK = 'public';

    /** الدور الذي يُمنح للمستخدمين القدامى (لم يكن لديهم أدوار). null = بلا دور */
    private const LEGACY_USER_ROLE = 'sales_owner';

    /** عند تطابق رقم الفرع (1) يتم تحديث الفرع الذي أنشأه الـ Installer ببيانات الفرع القديم */
    private const OVERWRITE_FIRST_BRANCH = true;

    /** أعمدة تشير إلى المستخدمين ويجب إعادة ربطها بعد ترحيل المستخدمين */
    private const USER_COLUMNS = ['created_by', 'approved_by', 'user_id'];

    private Connection $old;

    /** [old_user_id => new_user_id] */
    private array $userMap = [];

    private int $fallbackUserId = 0;

    /** كاش بيانات أعمدة الجداول الجديدة */
    private array $columnCache = [];

    /** [table => ['old' => n]] */
    private array $report = [];

    private array $missingImages = [];

    /** [opening_stock_item_id => opening_date] */
    private array $openingItemDates = [];

    /* ====================================================================
     |  نقطة التشغيل
     * ==================================================================== */

    public function run(): void
    {
        $this->connectLegacy();
        $this->assertReady();

        $this->say('بدء ترحيل البيانات ...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () {
                $this->migrateBranches();
                $this->migrateUsers();
                $this->migrateLookups();
                $this->migrateProducts();
                $this->migrateInventory();
                $this->migrateContacts();
                $this->migratePurchases();
                $this->syncProductCodeCounter();
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->verify();
        $this->say('اكتمل الترحيل بنجاح.');
    }

    /* ====================================================================
     |  التهيئة والفحوصات المسبقة
     * ==================================================================== */

    private function connectLegacy(): void
    {
        $default = config('database.connections.' . config('database.default'), []);

        config(['database.connections.legacy' => [
            'driver'    => 'mysql',
            'host'      => env('LEGACY_DB_HOST', $default['host'] ?? '127.0.0.1'),
            'port'      => env('LEGACY_DB_PORT', $default['port'] ?? '3306'),
            'database'  => env('LEGACY_DB_DATABASE', 'appstoredb'),
            'username'  => env('LEGACY_DB_USERNAME', $default['username'] ?? 'root'),
            'password'  => env('LEGACY_DB_PASSWORD', $default['password'] ?? ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ]]);

        DB::purge('legacy');
        $this->old = DB::connection('legacy');
        $this->old->getPdo(); // يفشل مبكراً إن كانت القاعدة القديمة غير موجودة

        if ($this->old->getDatabaseName() === DB::connection()->getDatabaseName()) {
            throw new RuntimeException('قاعدة البيانات القديمة والجديدة بنفس الاسم! استورد النسخة القديمة في قاعدة مستقلة (LEGACY_DB_DATABASE).');
        }
    }

    private function assertReady(): void
    {
        $oldSchema = $this->old->getSchemaBuilder();
        $newSchema = DB::getSchemaBuilder();

        $required = [
            'branches', 'users', 'categories', 'colors', 'sizes', 'products', 'product_variants',
            'inventories', 'opening_stock_entries', 'opening_stock_items', 'stock_movements',
        ];

        foreach ($required as $table) {
            if (! $oldSchema->hasTable($table)) {
                throw new RuntimeException("الجدول [{$table}] غير موجود في قاعدة البيانات القديمة.");
            }
            if (! $newSchema->hasTable($table)) {
                throw new RuntimeException("الجدول [{$table}] غير موجود في القاعدة الجديدة. شغّل migrate أولاً.");
            }
        }

        // حماية: الـ upsert يعيد كتابة الكميات، فلا نسمح بإعادة التشغيل بعد بدء العمل الفعلي
        foreach (['sale_invoices', 'sale_returns', 'customer_receipts', 'expenses', 'treasury_documents'] as $table) {
            if ($newSchema->hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("القاعدة الجديدة تحتوي بيانات تشغيلية في [{$table}]؛ تم إيقاف الترحيل حمايةً للبيانات.");
            }
        }

        if (DB::table('stock_movements')->where('movement_type', '!=', 'opening_stock')->exists()) {
            throw new RuntimeException('القاعدة الجديدة تحتوي حركات مخزون فعلية؛ تم إيقاف الترحيل حمايةً للبيانات.');
        }
    }

    /* ====================================================================
     |  1) الفروع
     * ==================================================================== */

    private function migrateBranches(): void
    {
        $this->say('ترحيل الفروع ...');

        foreach ($this->old->table('branches')->orderBy('id')->get() as $b) {
            $exists = DB::table('branches')->where('id', $b->id)->exists();

            if ($exists && $b->id == 1 && ! self::OVERWRITE_FIRST_BRANCH) {
                continue;
            }

            $name = $b->name;
            if (DB::table('branches')->where('name', $name)->where('id', '!=', $b->id)->exists()) {
                $name .= ' (مرحَّل)'; // الاسم فريد (unique)
            }

            $payload = [
                'name'       => $name,
                'address'    => $b->address,
                'phone'      => $b->phone,
                'type'       => $b->type ?: 'shop',
                'is_active'  => (int) $b->is_active,
                'updated_at' => $b->updated_at ?? now(),
            ];

            if ($exists) {
                DB::table('branches')->where('id', $b->id)->update($payload);
            } else {
                DB::table('branches')->insert($payload + [
                    'id'         => $b->id,
                    'created_at' => $b->created_at ?? now(),
                ]);
            }
        }

        $this->report['branches'] = ['old' => $this->old->table('branches')->count()];
    }

    /* ====================================================================
     |  2) المستخدمون (مع معالجة كلمات المرور وبناء خريطة المعرّفات)
     * ==================================================================== */

    private function migrateUsers(): void
    {
        $this->say('ترحيل المستخدمين ...');

        $defaultBranch = (int) DB::table('branches')->min('id');
        $roleId = self::LEGACY_USER_ROLE
            ? DB::table('roles')->where('name', self::LEGACY_USER_ROLE)->value('id')
            : null;

        foreach ($this->old->table('users')->orderBy('id')->get() as $u) {
            // إن وُجد مستخدم بنفس اسم المستخدم/البريد نعيد استخدامه ولا ننشئ نسخة مكررة
            $existing = DB::table('users')->where('username', $u->username)->first();
            if (! $existing && ! empty($u->email)) {
                $existing = DB::table('users')->where('email', $u->email)->first();
            }

            if ($existing) {
                $this->userMap[$u->id] = (int) $existing->id;
                $this->say("المستخدم [{$u->username}] موجود مسبقاً (id={$existing->id}) وتم ربطه.", 'warn');
                continue;
            }

            $this->userMap[$u->id] = (int) DB::table('users')->insertGetId([
                'name'              => $u->name,
                'username'          => $u->username,
                'email'             => $u->email ?: null,
                'password'          => $this->normalizePassword($u->password),
                'is_active'         => (int) $u->is_active,
                'remember_token'    => null,
                'role_id'           => $roleId,
                'branch_id'         => $u->branch_id ?: $defaultBranch,
                'email_verified_at' => null,
                'created_at'        => $u->created_at ?? now(),
                'updated_at'        => $u->updated_at ?? now(),
                'deleted_at'        => $u->deleted_at,
            ]);
        }

        // مستخدم احتياطي للحقول الإلزامية (created_by ...) عند غياب المطابقة
        $this->fallbackUserId = (int) (
            DB::table('application_installations')->value('owner_id')
            ?: DB::table('users')->orderBy('id')->value('id')
        );

        if ($this->fallbackUserId === 0) {
            throw new RuntimeException('لا يوجد أي مستخدم في النظام الجديد لاستخدامه كمرجع للحقول الإلزامية.');
        }

        $this->report['users'] = ['old' => $this->old->table('users')->count()];
    }

    /** يحافظ على الهاش الصالح (bcrypt…) ويُنشئ Hash::make للقيم النصية الصريحة أو غير المعروفة */
    private function normalizePassword(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            $this->say('مستخدم بلا كلمة مرور؛ تم توليد كلمة عشوائية (استخدم "نسيت كلمة المرور").', 'warn');
            return Hash::make(Str::random(24));
        }

        $info = Hash::info($value);
        if (($info['algoName'] ?? 'unknown') !== 'unknown') {
            return $value; // هاش صالح (bcrypt متوافق مع لارافل)
        }

        return Hash::make($value); // نص صريح أو تشفير غير مدعوم
    }

    /* ====================================================================
     |  3) الجداول المرجعية: التصنيفات - الألوان - المقاسات
     * ==================================================================== */

    private function migrateLookups(): void
    {
        $this->say('ترحيل التصنيفات والألوان والمقاسات ...');

        $this->copyTable('categories');
        $this->copyTable('colors');
        $this->copyTable('sizes');
    }

    /* 
    ====================================================================
     |  4) المنتجات والمتغيرات والصور
     * ================================== ================================== 
     * 
     * */

       /* ====================================================================
     |  4) المنتجات والمتغيرات والصور
     * ==================================================================== */

       /* ====================================================================
     |  4) المنتجات والمتغيرات والصور
     * ==================================================================== */

    private function migrateProducts(): void
    {
        $this->say('ترحيل المنتجات ومتغيراتها وتوليد مسارات الصور الفريدة ...');

        $this->copyTable('products', function (array $r) {
            // معالجة صورة المنتج الأساسية
            $r['image_path'] = $this->processAndOptimizeLegacyImage($r['image_path'] ?? null);
            return $r;
        });

        $this->copyTable('product_variants');

        $this->copyTable('product_images', function (array $r) {
            // معالجة صور المعرض الإضافية
            $uuidPath = $this->processAndOptimizeLegacyImage($r['image_path'] ?? null);
            
            // تعديل حاسم: التطبيق الجديد يقرأ المسار مباشرة من الـ UUID المتولد
            $r['image_path'] = $uuidPath ? $uuidPath . '/optimized.webp' : null;
            $r['thumbnail_path'] = $uuidPath ? $uuidPath . '/thumbnail.webp' : null;
            return $r;
        });
    }


    /**
     * توحيد مسار الصورة ليطابق قرص التخزين الجديد (relative path بدون storage/ أو public/)،
     * وتسجيل الملفات غير الموجودة للتنبيه فقط.
     */
    private function normalizeImagePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $p = ltrim(str_replace('\\', '/', trim($path)), '/');
        foreach (['storage/', 'public/', 'app/public/'] as $prefix) {
            if (str_starts_with($p, $prefix)) {
                $p = substr($p, strlen($prefix));
            }
        }

        if (! Storage::disk(self::IMAGE_DISK)->exists($p)) {
            $this->missingImages[$p] = true;
        }

        return $p;
    }

    /* ====================================================================
     |  5) المخزون: الأرصدة - مستندات أول المدة - الحركات
     * ==================================================================== */

    private function migrateInventory(): void
    {
        $this->say('ترحيل المخزون وحركاته ...');

        // أعمدة جديدة: value_minor (القيمة) + الأعمدة الأخرى تأخذ قيمها الافتراضية من القاعدة
        // (quarantined/damaged/reserved = 0). لا نكتب reserved_quantity عمداً.
        $this->copyTable('inventories', function (array $r) {
            $r['value_minor'] = $this->toMinor($r['average_cost']) * (int) $r['quantity'];
            return $r;
        });

        $this->copyTable('opening_stock_entries'); // version يأخذ الافتراضي 1
        $this->copyTable('opening_stock_items');
        $this->copyTable('inventory_setup_changes');

        // تواريخ أول المدة لكل بند، لتعبئة effective_date في حركات المخزون
        $this->openingItemDates = $this->old->table('opening_stock_items as i')
            ->join('opening_stock_entries as e', 'e.id', '=', 'i.opening_stock_entry_id')
            ->pluck('e.opening_date', 'i.id')
            ->all();

        $this->copyTable('stock_movements', function (array $r) {
            $qty = (int) $r['quantity_change'];

            $r['value_change_minor'] = $r['unit_cost'] !== null
                ? $this->toMinor($r['unit_cost']) * $qty
                : null;
            $r['stock_bucket']   = 'available';
            $r['effective_date'] = $this->movementDate($r);

            return $r;
        });
    }

    private function movementDate(array $r): string
    {
        if (str_ends_with((string) $r['source_type'], 'OpeningStockItem')
            && ! empty($this->openingItemDates[$r['source_id']])) {
            return (string) $this->openingItemDates[$r['source_id']];
        }

        return substr((string) ($r['created_at'] ?? now()->toDateTimeString()), 0, 10);
    }

    /** تحويل مبلغ عشري إلى الوحدة الصغرى (عدد صحيح) */
    private function toMinor(mixed $amount): int
    {
        return (int) round(((float) $amount) * self::MINOR_UNIT);
    }

    /* ====================================================================
     |  6) العملاء والموردون وطرق الدفع (فارغة في النسخة الحالية، تُنقل إن وُجدت)
     * ==================================================================== */

    private function migrateContacts(): void
    {
        $this->say('ترحيل العملاء والموردين ...');

        $this->copyTable('customers');
        $this->copyTable('suppliers');
        $this->copyTable('payment_methods');
    }

    /* ====================================================================
     |  7) المشتريات (فارغة في النسخة الحالية، تُنقل إن وُجدت)
     |     الأعمدة المالية الجديدة (financial_posted_at, financial_account_id ...) تبقى NULL
     |     ليُعتمد قيدها المالي لاحقاً عبر صلاحية finance.purchase_post.
     * ==================================================================== */

    private function migratePurchases(): void
    {
        $this->say('ترحيل المشتريات ...');

        $this->copyTable('purchase_invoices');
        $this->copyTable('purchase_invoice_items');
        $this->copyTable('supplier_payments');
    }

    private function syncProductCodeCounter(): void
    {
        if (! $this->old->getSchemaBuilder()->hasTable('product_code_counters')) {
            return;
        }

        $oldLast = (int) ($this->old->table('product_code_counters')->max('last_number') ?? 0);
        $current = DB::table('product_code_counters')->where('id', 1)->value('last_number');

        if ($current === null) {
            DB::table('product_code_counters')->insert([
                'id' => 1, 'last_number' => $oldLast, 'lock_version' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($oldLast > (int) $current) {
            DB::table('product_code_counters')->where('id', 1)->update(['last_number' => $oldLast]);
        }
    }

    /* ====================================================================
     |  المحرك العام للنسخ مع المطابقة الذكية
     * ==================================================================== */

    /**
     * ينسخ جدولاً من القديم إلى الجديد:
     *  - يطابق الأعمدة المشتركة بالاسم ويتجاهل الأعمدة التي حُذفت.
     *  - يطبّق $transform (إن وُجد) لإضافة/تعديل الحقول الجديدة.
     *  - يولّد قيماً افتراضية ذكية لأي عمود جديد NOT NULL بلا قيمة افتراضية.
     *  - يستخدم upsert على id فيحافظ على المعرّفات (علاقات سليمة) ويسمح بإعادة التشغيل.
     */
    private function copyTable(string $table, ?callable $transform = null): void
    {
        if (! $this->old->getSchemaBuilder()->hasTable($table)) {
            $this->say("تخطي [{$table}]: غير موجود في القاعدة القديمة.", 'warn');
            return;
        }

        $meta = $this->columnsOf($table);

        $this->old->table($table)->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($table, $meta, $transform) {
            $batch = [];

            foreach ($rows as $row) {
                $data = $this->remapUsers((array) $row);

                if ($transform) {
                    $data = $transform($data);
                    if ($data === null) {
                        continue;
                    }
                }

                $batch[] = $this->conform($data, $meta);
            }

            if ($batch) {
                $update = array_values(array_diff(array_keys($batch[0]), ['id', 'created_at']));
                DB::table($table)->upsert($batch, ['id'], $update);
            }

            return true;
        });

        $this->report[$table] = ['old' => $this->old->table($table)->count()];
    }

    /** استبدال معرّفات المستخدمين القدامى بالجدد */
    private function remapUsers(array $row): array
    {
        foreach (self::USER_COLUMNS as $col) {
            if (array_key_exists($col, $row) && $row[$col] !== null) {
                $row[$col] = $this->userMap[$row[$col]] ?? $this->fallbackUserId;
            }
        }

        return $row;
    }

    /** مطابقة الصف مع أعمدة الجدول الجديد وتعبئة الحقول الإلزامية */
    private function conform(array $data, array $meta): array
    {
        $out = [];

        foreach ($meta as $name => $m) {
            $present = array_key_exists($name, $data);
            $value   = $present ? $data[$name] : null;

            if ($present && $value !== null) {
                $out[$name] = $value;
                continue;
            }

            $autoInc = str_contains(strtolower((string) $m->extra), 'auto_increment');
            $generated = str_contains(strtolower((string) $m->extra), 'generated');

            if ($m->nullable === 'NO' && ! $autoInc && ! $generated && ($present || $m->dflt === null)) {
                $out[$name] = $this->smartDefault($m, $data);
                continue;
            }

            if ($present) {
                $out[$name] = null;
            }
        }

        return $out;
    }

    /** قيمة افتراضية مناسبة لنوع الحقل واسمه */
    private function smartDefault(object $m, array $row): mixed
    {
        $name = $m->name;
        $type = strtolower($m->type);

        if (in_array($name, self::USER_COLUMNS, true)) {
            return $this->fallbackUserId;
        }
        if (str_contains($name, 'uuid')) {
            return (string) Str::uuid();
        }
        if (str_contains($name, 'hash')) {
            return hash('sha256', $name . '|' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        if ($name === 'status') {
            return 'approved';
        }

        return match (true) {
            in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'], true) => 0,
            $type === 'date' => isset($row['created_at'])
                ? substr((string) $row['created_at'], 0, 10)
                : now()->toDateString(),
            in_array($type, ['datetime', 'timestamp'], true) => $row['created_at'] ?? now()->toDateTimeString(),
            $type === 'json' => '{}',
            $type === 'enum' => preg_match("/^enum\('([^']*)'/", (string) $m->full_type, $mm) ? $mm[1] : '',
            default => '',
        };
    }

    /** بيانات أعمدة الجدول الجديد من information_schema */
    private function columnsOf(string $table): array
    {
        return $this->columnCache[$table] ??= collect(DB::select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, COLUMN_TYPE AS full_type,
                    IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table]
        ))->keyBy('name')->all();
    }

    /* ====================================================================
     |  التحقق بعد الترحيل
     * ==================================================================== */

    private function verify(): void
    {
        $this->say('--- تقرير التحقق ---');

        $rows = [];
        $issues = 0;

        foreach ($this->report as $table => $r) {
            $new = DB::table($table)->count();
            $ok  = $new >= $r['old'];
            $issues += $ok ? 0 : 1;
            $rows[] = [$table, $r['old'], $new, $ok ? 'OK' : 'ناقص!'];
        }

        if ($this->command) {
            $this->command->table(['الجدول', 'قديم', 'جديد', 'الحالة'], $rows);
        }

        $orphans = [
            ['products → categories',        'products', 'category_id', 'categories'],
            ['variants → products',          'product_variants', 'product_id', 'products'],
            ['variants → colors',            'product_variants', 'color_id', 'colors'],
            ['variants → sizes',             'product_variants', 'size_id', 'sizes'],
            ['images → products',            'product_images', 'product_id', 'products'],
            ['images → colors',              'product_images', 'color_id', 'colors'],
            ['inventories → branches',       'inventories', 'branch_id', 'branches'],
            ['inventories → variants',       'inventories', 'product_variant_id', 'product_variants'],
            ['opening entries → branches',   'opening_stock_entries', 'branch_id', 'branches'],
            ['opening entries → users',      'opening_stock_entries', 'created_by', 'users'],
            ['opening items → entries',      'opening_stock_items', 'opening_stock_entry_id', 'opening_stock_entries'],
            ['opening items → variants',     'opening_stock_items', 'product_variant_id', 'product_variants'],
            ['movements → variants',         'stock_movements', 'product_variant_id', 'product_variants'],
            ['movements → branches',         'stock_movements', 'branch_id', 'branches'],
            ['users → branches',             'users', 'branch_id', 'branches'],
            ['users → roles',                'users', 'role_id', 'roles'],
        ];

        foreach ($orphans as [$label, $child, $fk, $parent]) {
            $count = (int) DB::selectOne(
                "SELECT COUNT(*) AS c FROM {$child} ch
                  LEFT JOIN {$parent} p ON p.id = ch.{$fk}
                 WHERE ch.{$fk} IS NOT NULL AND p.id IS NULL"
            )->c;

            if ($count > 0) {
                $issues++;
                $this->say("علاقات يتيمة: {$label} = {$count}", 'error');
            }
        }

        // تطابق رصيد المخزون مع مجموع الحركات
        $mismatch = (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM inventories i
               LEFT JOIN (SELECT branch_id, product_variant_id, SUM(quantity_change) AS s
                            FROM stock_movements WHERE stock_bucket = 'available'
                           GROUP BY branch_id, product_variant_id) m
                 ON m.branch_id = i.branch_id AND m.product_variant_id = i.product_variant_id
              WHERE COALESCE(m.s, 0) <> i.quantity"
        )->c;

        if ($mismatch > 0) {
            $issues++;
            $this->say("أرصدة مخزون لا تطابق مجموع حركاتها: {$mismatch}", 'error');
        }

        // مقارنة القيمة الإجمالية للمخزون
        $expected = $this->toMinor($this->old->table('inventories')->selectRaw('SUM(quantity * average_cost) AS v')->value('v') ?? 0);
        $actual   = (int) DB::table('inventories')->sum('value_minor');
        $this->say("قيمة المخزون (وحدة صغرى): المتوقع={$expected} الفعلي={$actual}", $expected === $actual ? 'info' : 'error');
        $issues += $expected === $actual ? 0 : 1;

        // الصور
        if ($this->missingImages) {
            $this->say('صور غير موجودة على القرص [' . self::IMAGE_DISK . '] (' . count($this->missingImages) . ') — انسخ مجلد الصور ثم أعد الفحص:', 'warn');
            foreach (array_slice(array_keys($this->missingImages), 0, 15) as $p) {
                $this->say("  - {$p}", 'line');
            }
        } else {
            $this->say('جميع مسارات الصور موجودة على القرص.');
        }

        $this->say($issues === 0 ? 'لا توجد أي مشاكل.' : "عدد المشاكل المكتشفة: {$issues}", $issues === 0 ? 'info' : 'warn');
    }

    /* ====================================================================
     |  أدوات الإخراج
     * ==================================================================== */

    private function say(string $message, string $level = 'info'): void
    {
        if ($this->command) {
            $this->command->{$level}($message);
        }
    }
        /**
     * دالة مخصصة لمعالجة الصور القديمة:
     * تقرأ الملف الخام، تنشئ مجلد UUID فريد، وتنتج نسختين optimized و thumbnail بصيغة webp
     */
    private function processAndOptimizeLegacyImage(?string $oldPath): ?string
    {
        if ($oldPath === null || trim($oldPath) === '') {
            return null;
        }

        // استخراج اسم ملف الصورة النقي (مثل image.png)
        $filename = basename(str_replace('\\', '/', trim($oldPath)));
        // تعديل حاسم: تحويل اسم الملف المطلوب إلى حروف صغيرة ليطابق المجلد الفعلي عندك
        $filename = strtolower($filename); 
        
        // المجلد المؤقت الذي ستضع فيه صورك القديمة على جهازك
        $sourceFile = storage_path('app/public/legacy_raw_images/' . $filename);

        // إذا لم تكن الصورة القديمة موجودة فعلياً في المجلد، نتخطاها حماية للسكربت
        if (!file_exists($sourceFile) || is_dir($sourceFile)) {
            $this->missingImages[$filename] = true;
            return 'missing-assets/' . $filename;
        }

        // 1. توليد كود UUID فريد للمجلد الجديد
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $targetFolder = 'products/colors/' . $uuid;
        $targetFullPath = storage_path('app/public/' . $targetFolder);

        if (!file_exists($targetFullPath)) {
            mkdir($targetFullPath, 0755, true);
        }

        try {
            $imageInfo = getimagesize($sourceFile);
            if (!$imageInfo) {
                return null;
            }

            // قراءة الصورة الأصلية حسب نوعها (PNG, JPEG, GIF, WEBP)
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG: $srcImg = imagecreatefromjpeg($sourceFile); break;
                case IMAGETYPE_PNG:  $srcImg = imagecreatefrompng($sourceFile); break;
                case IMAGETYPE_GIF:  $srcImg = imagecreatefromgif($sourceFile); break;
                case IMAGETYPE_WEBP: $srcImg = imagecreatefromwebp($sourceFile); break;
                default: return null;
            }

            if (!$srcImg) {
                return null;
            }

            $origWidth = imagesx($srcImg);
            $origHeight = imagesy($srcImg);

            // 2. توليد النسخة المحسنة للعرض (Optimized) - أقصى عرض 800 بكسل نسبة وتناسب
            $optWidth = min($origWidth, 800);
            $optHeight = (int)($origHeight * ($optWidth / $origWidth));
            $optImg = imagecreatetruecolor($optWidth, $optHeight);
            imagealphablending($optImg, false);
            imagesavealpha($optImg, true);
            imagecopyresampled($optImg, $srcImg, 0, 0, 0, 0, $optWidth, $optHeight, $origWidth, $origHeight);
            imagewebp($optImg, $targetFullPath . '/optimized.webp', 85);
            imagedestroy($optImg);

            // 3. توليد النسخة المصغرة للتصفح السريع (Thumbnail) - مربع بأبعاد 200x200
            $thumbSize = 200;
            $thumbImg = imagecreatetruecolor($thumbSize, $thumbSize);
            imagealphablending($thumbImg, false);
            imagesavealpha($thumbImg, true);
            imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $thumbSize, $thumbSize, $origWidth, $origHeight);
            imagewebp($thumbImg, $targetFullPath . '/thumbnail.webp', 75);
            imagedestroy($thumbImg);

            imagedestroy($srcImg);

            // إرجاع مسار المجلد الجديد ليتم تخزينه في قاعدة البيانات
            return $targetFolder;

        } catch (\Throwable $e) {
            $this->say("فشلت معالجة الصورة للملف [{$filename}]: " . $e->getMessage(), 'error');
            return 'failed-assets/' . $filename;
        }
    }

}