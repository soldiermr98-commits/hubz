<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiContentService;
use Throwable;

/**
 * کنترلر صف کارهای هوش مصنوعی بدون توهم (AI Tasks & Remote Worker - مرحله S5)
 *
 * شامل:
 * ۱. مشاهده وضعیت صف ai_tasks و فهرست فیلدهای استفاده‌شده (used_fields_json) در پنل مدیریت
 * ۲. ثبت تکی یا گروهی تسک‌های تولید متن کسب‌وکار (business_text) و پیش‌نویس مقاله (article_draft)
 * ۳. اجرای فوری دسته‌ای روی سرور (بدون نیاز به SSH) با گاردریل ضد توهم
 * ۴. اندپوینت‌های مدل Pull برای اسکریپت مستقل ai-worker/run.php (GET next و POST complete)
 */
final class AiWorkerController
{
    /**
     * دریافت لیست تسک‌های هوش مصنوعی و آمار صف برای پنل مدیریت
     * GET /api/v1/ai/tasks
     */
    public static function index(Request $request): void
    {
        Auth::requireRole(['admin', 'editor']);

        try {
            $tasks = DB::fetchAll(
                "SELECT t.*,
                        CASE
                            WHEN t.type = 'business_text' THEN (SELECT b.name FROM businesses b WHERE b.id = t.target_id LIMIT 1)
                            WHEN t.type = 'article_draft' THEN (SELECT a.title FROM articles a WHERE a.id = t.target_id LIMIT 1)
                            ELSE NULL
                        END AS target_title
                 FROM ai_tasks t
                 ORDER BY t.id DESC
                 LIMIT 100"
            );

            $statsRows = DB::fetchAll(
                "SELECT status, COUNT(*) AS cnt FROM ai_tasks GROUP BY status"
            );
            $stats = [
                'queued'  => 0,
                'claimed' => 0,
                'done'    => 0,
                'failed'  => 0,
            ];
            foreach ($statsRows as $sr) {
                $st = (string) ($sr['status'] ?? '');
                if (isset($stats[$st])) {
                    $stats[$st] = (int) $sr['cnt'];
                }
            }

            $tokenRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'collector_bearer_token' LIMIT 1");

            Response::json([
                'ok'           => true,
                'tasks'        => $tasks,
                'stats'        => $stats,
                'bearer_token' => '',
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok'    => false,
                'error' => 'خطا در خواندن صف هوش مصنوعی: ' . $e->getMessage(),
            ], 500);
        }
    }

    public static function disabled(Request $request): void
    {
        Response::json(['ok' => false, 'error' => 'هوش مصنوعی در این فاز غیرفعال است.'], 503);
    }

    /**
     * ثبت تسک جدید در صف ai_tasks (تکی یا دسته‌ای برای تمام کسب‌وکارها و مقالات)
     * POST /api/v1/ai/tasks/enqueue
     */
    public static function enqueue(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        try {
            $mode = trim((string) $request->input('mode', 'batch_all'));
            $type = trim((string) $request->input('type', 'business_text'));
            $targetId = (int) $request->input('target_id', 0);

            $enqueuedCount = 0;

            if ($mode === 'single' && $targetId > 0) {
                if (!in_array($type, ['business_text', 'article_draft'], true)) {
                    Response::json(['ok' => false, 'error' => 'نوع تسک معتبر نیست.'], 422);
                }

                $input = $type === 'business_text'
                    ? AiContentService::buildBusinessInput($targetId)
                    : AiContentService::buildArticleInput($targetId);

                if ($input === null) {
                    Response::json(['ok' => false, 'error' => 'آیتم هدف در دیتابیس یافت نشد.'], 404);
                }

                DB::query(
                    "INSERT INTO ai_tasks (type, target_id, input_json, status, attempts)
                     VALUES (:type, :tid, :input_json, 'queued', 0)",
                    [
                        'type'       => $type,
                        'tid'        => $targetId,
                        'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                );
                $enqueuedCount = 1;
            } else {
                // ثبت دسته‌ای تسک برای کسب‌وکارهای منتشرشده و مقالات موجود
                $businesses = DB::fetchAll(
                    "SELECT id FROM businesses
                     WHERE status IN ('published', 'pending_review')
                     ORDER BY internal_score DESC, id DESC
                     LIMIT 25"
                );

                foreach ($businesses as $b) {
                    $bid = (int) $b['id'];
                    $existingQueued = DB::fetchOne(
                        "SELECT id FROM ai_tasks
                         WHERE type = 'business_text' AND target_id = :tid AND status IN ('queued', 'claimed')
                         LIMIT 1",
                        ['tid' => $bid]
                    );
                    if ($existingQueued !== null) {
                        continue;
                    }
                    $input = AiContentService::buildBusinessInput($bid);
                    if ($input === null) {
                        continue;
                    }
                    DB::query(
                        "INSERT INTO ai_tasks (type, target_id, input_json, status, attempts)
                         VALUES ('business_text', :tid, :input_json, 'queued', 0)",
                        [
                            'tid'        => $bid,
                            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );
                    $enqueuedCount++;
                }

                $articles = DB::fetchAll("SELECT id FROM articles ORDER BY id DESC LIMIT 10");
                foreach ($articles as $a) {
                    $aid = (int) $a['id'];
                    $existingQueued = DB::fetchOne(
                        "SELECT id FROM ai_tasks
                         WHERE type = 'article_draft' AND target_id = :tid AND status IN ('queued', 'claimed')
                         LIMIT 1",
                        ['tid' => $aid]
                    );
                    if ($existingQueued !== null) {
                        continue;
                    }
                    $input = AiContentService::buildArticleInput($aid);
                    if ($input === null) {
                        continue;
                    }
                    DB::query(
                        "INSERT INTO ai_tasks (type, target_id, input_json, status, attempts)
                         VALUES ('article_draft', :tid, :input_json, 'queued', 0)",
                        [
                            'tid'        => $aid,
                            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );
                    $enqueuedCount++;
                }
            }

            Response::json([
                'ok'             => true,
                'enqueued_count' => $enqueuedCount,
                'message'        => sprintf('%d تسک جدید با داده‌های راستی‌آزمایی‌شده به صف ai_tasks اضافه شد.', $enqueuedCount),
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok'    => false,
                'error' => 'خطا در افزودن تسک به صف هوش مصنوعی: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * اجرای فوری تسک‌های در صف مستقیماً روی سرور (بدون نیاز به SSH) همراه با گاردریل ضد توهم
     * POST /api/v1/ai/tasks/run-batch
     */
    public static function runBatch(Request $request): void
    {
        Csrf::verifyOrFail($request);
        Auth::requireRole(['admin', 'editor']);

        try {
            $limit = max(1, min(25, (int) $request->input('limit', 10)));

            $queuedTasks = DB::fetchAll(
                "SELECT * FROM ai_tasks
                 WHERE status IN ('queued', 'claimed')
                 ORDER BY id ASC
                 LIMIT {$limit}"
            );

            // اگر صف خالی بود، خودکار چند تسک از کسب‌وکارها و مقالات بساز و اجرا کن تا کاربر با ۱ کلیک نتیجه را ببیند
            if (empty($queuedTasks)) {
                $bizList = DB::fetchAll(
                    "SELECT id FROM businesses WHERE status = 'published' ORDER BY internal_score DESC LIMIT 5"
                );
                foreach ($bizList as $b) {
                    $input = AiContentService::buildBusinessInput((int) $b['id']);
                    if ($input !== null) {
                        DB::query(
                            "INSERT INTO ai_tasks (type, target_id, input_json, status, attempts)
                             VALUES ('business_text', :tid, :input_json, 'queued', 0)",
                            [
                                'tid'        => (int) $b['id'],
                                'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]
                        );
                    }
                }

                $artList = DB::fetchAll("SELECT id FROM articles ORDER BY id DESC LIMIT 2");
                foreach ($artList as $a) {
                    $input = AiContentService::buildArticleInput((int) $a['id']);
                    if ($input !== null) {
                        DB::query(
                            "INSERT INTO ai_tasks (type, target_id, input_json, status, attempts)
                             VALUES ('article_draft', :tid, :input_json, 'queued', 0)",
                            [
                                'tid'        => (int) $a['id'],
                                'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]
                        );
                    }
                }

                $queuedTasks = DB::fetchAll(
                    "SELECT * FROM ai_tasks WHERE status = 'queued' ORDER BY id ASC LIMIT {$limit}"
                );
            }

            $processed = 0;
            $succeeded = 0;
            $failed = 0;

            foreach ($queuedTasks as $task) {
                $taskId = (int) $task['id'];
                $type = (string) $task['type'];
                $targetId = (int) $task['target_id'];
                $input = json_decode((string) ($task['input_json'] ?? '{}'), true);
                if (!is_array($input)) {
                    $input = [];
                }

                $processed++;

                $generated = AiContentService::generateFactGroundedOutput($type, $input);
                $validation = AiContentService::validateNoHallucination($input, $generated['output']);

                if (!$validation['valid']) {
                    $failed++;
                    DB::query(
                        "UPDATE ai_tasks
                         SET status = 'failed',
                             attempts = attempts + 1,
                             error = :err,
                             updated_at = NOW()
                         WHERE id = :id",
                        [
                            'err' => $validation['reason'],
                            'id'  => $taskId,
                        ]
                    );
                    continue;
                }

                AiContentService::applyOutputToTarget($type, $targetId, $generated['output']);

                DB::query(
                    "UPDATE ai_tasks
                     SET status = 'done',
                         output_json = :out,
                         used_fields_json = :used,
                         model = :model,
                         attempts = attempts + 1,
                         error = NULL,
                         claimed_at = NOW(),
                         updated_at = NOW()
                     WHERE id = :id",
                    [
                        'out'   => json_encode($generated['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'used'  => json_encode($generated['used_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'model' => $generated['model'],
                        'id'    => $taskId,
                    ]
                );
                $succeeded++;
            }

            Response::json([
                'ok'        => true,
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed'    => $failed,
                'message'   => sprintf(
                    'پردازش %d تسک هوش مصنوعی تکمیل شد (%d موفق بدون توهم، %d ردشده توسط گاردریل). کش صفحات عمومی نیز به‌روزرسانی گردید.',
                    $processed,
                    $succeeded,
                    $failed
                ),
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok'    => false,
                'error' => 'خطا در اجرای دسته‌ای تسک‌های هوش مصنوعی: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت تسک بعدی توسط اسکریپت راه دور ai-worker/run.php (مدل Pull)
     * GET /api/v1/ai/tasks/next
     */
    public static function pullNextTask(Request $request): void
    {
        self::verifyWorkerTokenOrSession($request);

        try {
            $task = DB::fetchOne(
                "SELECT * FROM ai_tasks
                 WHERE status = 'queued'
                    OR (status = 'claimed' AND claimed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
                 ORDER BY id ASC
                 LIMIT 1"
            );

            if ($task === null) {
                Response::json([
                    'ok'   => true,
                    'task' => null,
                ]);
            }

            $taskId = (int) $task['id'];
            DB::query(
                "UPDATE ai_tasks
                 SET status = 'claimed',
                     claimed_at = NOW(),
                     attempts = attempts + 1,
                     updated_at = NOW()
                 WHERE id = :id",
                ['id' => $taskId]
            );

            Response::json([
                'ok'   => true,
                'task' => [
                    'id'        => $taskId,
                    'type'      => (string) $task['type'],
                    'target_id' => (int) $task['target_id'],
                    'input'     => json_decode((string) $task['input_json'], true) ?: [],
                ],
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok'    => false,
                'error' => 'خطا در واکشی تسک بعدی: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ثبت نتیجه تولیدشده توسط اسکریپت راه دور ai-worker/run.php همراه با اعتبارسنجی گاردریل ضد توهم
     * POST /api/v1/ai/tasks/complete
     */
    public static function completeTask(Request $request): void
    {
        self::verifyWorkerTokenOrSession($request);

        try {
            $taskId = (int) $request->input('task_id', 0);
            $output = $request->input('output', []);
            $usedFields = $request->input('used_fields', []);
            $model = trim((string) $request->input('model', 'remote-ai-worker-v1'));

            if ($taskId <= 0 || !is_array($output)) {
                Response::json(['ok' => false, 'error' => 'شناسه تسک یا خروجی ارسال‌شده معتبر نیست.'], 422);
            }

            $task = DB::fetchOne("SELECT * FROM ai_tasks WHERE id = :id LIMIT 1", ['id' => $taskId]);
            if ($task === null) {
                Response::json(['ok' => false, 'error' => 'تسک مورد نظر یافت نشد.'], 404);
            }

            $input = json_decode((string) ($task['input_json'] ?? '{}'), true);
            if (!is_array($input)) {
                $input = [];
            }

            $validation = AiContentService::validateNoHallucination($input, $output);
            if (!$validation['valid']) {
                DB::query(
                    "UPDATE ai_tasks
                     SET status = 'failed',
                         error = :err,
                         updated_at = NOW()
                     WHERE id = :id",
                    [
                        'err' => $validation['reason'],
                        'id'  => $taskId,
                    ]
                );

                Response::json([
                    'ok'    => false,
                    'error' => $validation['reason'],
                ], 422);
            }

            $type = (string) $task['type'];
            $targetId = (int) $task['target_id'];

            AiContentService::applyOutputToTarget($type, $targetId, $output);

            DB::query(
                "UPDATE ai_tasks
                 SET status = 'done',
                     output_json = :out,
                     used_fields_json = :used,
                     model = :model,
                     error = NULL,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'out'   => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'used'  => json_encode(is_array($usedFields) ? $usedFields : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'model' => $model,
                    'id'    => $taskId,
                ]
            );

            Response::json([
                'ok'      => true,
                'task_id' => $taskId,
                'message' => 'خروجی تسک پس از تأیید گاردریل ضد توهم با موفقیت ثبت و اعمال شد.',
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok'    => false,
                'error' => 'خطا در ثبت خروجی تسک هوش مصنوعی: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * بررسی توکن Bearer برای ورکر راه دور یا نشست مدیر
     */
    private static function verifyWorkerTokenOrSession(Request $request): void
    {
        if (Auth::user() !== null) {
            return;
        }

        $bearer = $request->bearerToken();
        $tokenRow = DB::fetchOne("SELECT v FROM settings WHERE k = 'collector_bearer_token' LIMIT 1");
        $expected = (string) ($tokenRow['v'] ?? 'hhx-collector-bearer-token-local-dev');

        if ($bearer === null || $expected === '' || !hash_equals($expected, $bearer)) {
            Response::json([
                'ok'    => false,
                'error' => 'توکن احراز هویت Bearer برای ai-worker معتبر نیست.',
            ], 401);
        }
    }
}
