<?php

declare(strict_types=1);

/**
 * Integration tests: flip cards (بطاقات الأخطاء, Prompt 13) — canonical
 * location validation, the active review queue, review tracking with
 * the active → in_review auto-promotion, the status machine and the
 * explicit-delete cascade of history.
 * Run: php tests/Integration/FlipCardTest.php (needs DB_* env)
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Database;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\FlipCardStatus;
use App\Services\AuthService;
use App\Services\FlipCardService;

if (!databaseAvailable()) {
    echo "SKIPPED: database not reachable (set DB_* environment variables)\n";
    exit(0);
}

requireTestingDatabase();
clearCanonicalTables();
loadSyntheticFixture();

$aEmail = 'flip-test@example.com';
$bEmail = 'flip-test-2@example.com';

// Idempotent re-runs: drop leftovers from a previously interrupted run.
Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);

/** Asserts a callable fails with the first validation error on $field. */
function expectFieldError(string $name, callable $fn, string $field): void
{
    try {
        $fn();
        check($name, false, 'nothing thrown');
    } catch (ValidationException $e) {
        $errors = $e->getErrors();
        $actual = $errors[0]['field'] ?? null;
        check($name, $actual === $field, 'expected field ' . $field . ' got ' . json_encode($errors));
    }
}

try {
    $auth = new AuthService();
    $register = static function (string $email, string $name) use ($auth): int {
        return (int) $auth->register(
            ['email' => $email, 'password' => 'Password123!', 'display_name' => $name],
            '127.0.0.1',
            'test'
        )['user']['id'];
    };

    $a = $register($aEmail, 'Flip A');
    $b = $register($bEmail, 'Flip B');

    $svc = new FlipCardService();

    $categoryId = static fn (string $slug): int =>
        (int) Database::scalar('SELECT id FROM flip_card_categories WHERE slug = ?', [$slug]);
    $flag = static fn (int $userId, int $catId, int $s, int $ay, int $p, array $extra = []): array =>
        $svc->create($userId, $extra + [
            'surah_number' => $s,
            'ayah_number' => $ay,
            'page_number' => $p,
            'category_id' => $catId,
            'error_note' => 'Lost the transition',
            'context_note' => null,
            'severity' => null,
        ]);
    $cardCount = static fn (int $userId): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM flip_cards WHERE user_id = ?', [$userId]);
    $reviewCount = static fn (int $cardId): int =>
        (int) Database::scalar('SELECT COUNT(*) FROM flip_card_reviews WHERE card_id = ?', [$cardId]);

    // === seeded vocabulary ===================================================
    $categories = $svc->categories()['categories'];
    checkEquals('categories: 7 seeded', 7, count($categories));
    checkEquals(
        'categories: slugs in sort order',
        ['forgotten_adjacent_ayah', 'verse_mistake', 'similar_ayah_confusion', 'hesitation', 'recurring_mistake', 'weak_location', 'other'],
        array_column($categories, 'slug')
    );
    check('categories: arabic names present', $categories[0]['name_ar'] !== '');

    // === location validation =================================================
    expectFieldError(
        'create: ayah on wrong page → page_number',
        fn () => $flag($a, $categoryId('verse_mistake'), 3, 7, 1),
        'page_number'
    );
    expectFieldError(
        'create: page outside dataset → page_number',
        fn () => $flag($a, $categoryId('verse_mistake'), 3, 7, 99),
        'page_number'
    );
    expectFieldError(
        'create: unknown location → ayah_number',
        fn () => $flag($a, $categoryId('verse_mistake'), 99, 1, 1),
        'ayah_number'
    );
    expectFieldError(
        'create: unknown category → category_id',
        fn () => $flag($a, 99999, 3, 7, 12),
        'category_id'
    );
    checkEquals('failed creates leave no rows', 0, $cardCount($a));

    // === valid creates =======================================================
    $catMistake = $categoryId('verse_mistake');
    $catSimilar = $categoryId('similar_ayah_confusion');

    $cardA = $flag($a, $catMistake, 3, 7, 12)['card'];
    $cardB = $flag($a, $catSimilar, 3, 8, 13, ['severity' => 'high'])['card'];
    $cardC = $flag($a, $catMistake, 1, 1, 1, ['context_note' => 'First page warm-up'])['card'];

    checkEquals('create: defaults status active', 'active', $cardA['status']);
    checkEquals('create: defaults review_count 0', 0, $cardA['review_count']);
    checkEquals('create: defaults severity medium', 'medium', $cardA['severity']);
    checkEquals('create: no review stamp yet', null, $cardA['last_reviewed_at']);
    checkEquals('create: no mastered stamp yet', null, $cardA['mastered_at']);
    checkEquals('create: no next review scheduled', null, $cardA['next_review_at']);
    check('create: created_at stamped', $cardA['created_at'] !== '');
    checkEquals('create: explicit severity stored', 'high', $cardB['severity']);
    checkEquals('create: context note stored', 'First page warm-up', $cardC['context_note']);
    checkEquals(
        'create: category embedded',
        ['similar_ayah_confusion', 3],
        [$cardB['category_slug'], $cardB['surah_number']]
    );
    checkEquals(
        'create: spanning ayah accepted on continuation page',
        [3, 8, 13],
        [$cardB['surah_number'], $cardB['ayah_number'], $cardB['page_number']]
    );

    // === list & filters ======================================================
    $ids = static fn (array $page): array => array_column($page['cards'], 'id');
    checkEquals('list: newest first', [$cardC['id'], $cardB['id'], $cardA['id']], $ids($svc->list($a, null, null, 20)));
    checkEquals('list: limit respected', [$cardC['id'], $cardB['id']], $ids($svc->list($a, null, null, 2)));
    checkEquals(
        'list: status filter',
        [$cardB['id']],
        $ids($svc->list($a, 'active', $catSimilar, 20))
    );
    checkEquals('list: category filter', [$cardC['id'], $cardA['id']], $ids($svc->list($a, null, $catMistake, 20)));
    checkEquals('list: isolation (b sees none)', [], $ids($svc->list($b, null, null, 20)));

    // === queue ordering ======================================================
    checkEquals('queue: empty for fresh user', [], $ids($svc->queue($b, 20)));
    checkEquals('queue: active cards, oldest first', [$cardA['id'], $cardB['id'], $cardC['id']], $ids($svc->queue($a, 20)));

    // === review tracking =====================================================
    $afterFirst = $svc->review($a, $cardA['id'], [
        'result' => 'forgotten',
        'duration_seconds' => 45,
        'notes' => 'Mixed up with 2:3',
    ]);
    checkEquals('review: counter bumped', 1, $afterFirst['card']['review_count']);
    check('review: last_reviewed_at stamped', $afterFirst['card']['last_reviewed_at'] !== null);
    checkEquals('review: auto-promotes active → in_review', 'in_review', $afterFirst['card']['status']);
    checkEquals('review: history appended', 1, count($afterFirst['reviews']));
    checkEquals('review: history result stored', 'forgotten', $afterFirst['reviews'][0]['result']);
    checkEquals('review: history duration stored', 45, $afterFirst['reviews'][0]['duration_seconds']);
    checkEquals('review: no scheduler side effect', null, $afterFirst['card']['next_review_at']);

    $afterSecond = $svc->review($a, $cardA['id'], ['result' => 'recalled']);
    checkEquals('review: second review bumps again', 2, $afterSecond['card']['review_count']);
    checkEquals('review: stays in_review afterwards', 'in_review', $afterSecond['card']['status']);
    checkEquals('review: history rows now 2', 2, count($afterSecond['reviews']));
    checkEquals(
        'review: history is append-only (first row kept)',
        'forgotten',
        $afterSecond['reviews'][1]['result']
    );
    checkEquals('review: rows match counter', $reviewCount($cardA['id']), $afterSecond['card']['review_count']);

    $svc->review($a, $cardB['id'], ['result' => 'partial', 'duration_seconds' => 0, 'notes' => null]);
    checkEquals(
        'queue: never-reviewed first, least recently reviewed last',
        [$cardC['id'], $cardA['id'], $cardB['id']],
        $ids($svc->queue($a, 20))
    );
    checkEquals('queue: limit respected', [$cardC['id']], $ids($svc->queue($a, 1)));

    // === status machine ======================================================
    $mastered = $svc->changeStatus($a, $cardA['id'], FlipCardStatus::Mastered)['card'];
    checkEquals('master: status applied', 'mastered', $mastered['status']);
    check('master: mastered_at stamped', $mastered['mastered_at'] !== null);
    checkEquals('master: history retained', 2, count($svc->detail($a, $cardA['id'])['reviews']));
    checkEquals('master: leaves the queue', [$cardC['id'], $cardB['id']], $ids($svc->queue($a, 20)));
    checkEquals('master: status filter finds it', [$cardA['id']], $ids($svc->list($a, 'mastered', null, 20)));

    $same = $svc->changeStatus($a, $cardA['id'], FlipCardStatus::Mastered)['card'];
    checkEquals('same state: idempotent no-op', 'mastered', $same['status']);

    $reopened = $svc->changeStatus($a, $cardA['id'], FlipCardStatus::Active)['card'];
    checkEquals('reopen: back to active', 'active', $reopened['status']);
    check('reopen: mastered_at kept as history', $reopened['mastered_at'] !== null);
    checkEquals('reopen: back in the queue', [$cardC['id'], $cardA['id'], $cardB['id']], $ids($svc->queue($a, 20)));

    $archived = $svc->changeStatus($a, $cardC['id'], FlipCardStatus::Archived)['card'];
    checkEquals('archive: status applied', 'archived', $archived['status']);
    checkEquals('archive: leaves the queue', [$cardA['id'], $cardB['id']], $ids($svc->queue($a, 20)));
    $restored = $svc->changeStatus($a, $cardC['id'], FlipCardStatus::Active)['card'];
    checkEquals('restore: archived → active', 'active', $restored['status']);

    // === ownership isolation =================================================
    checkThrows(
        'isolation: b cannot read a\'s card',
        fn () => $svc->detail($b, $cardA['id']),
        NotFoundException::class
    );
    checkThrows(
        'isolation: b cannot review a\'s card',
        fn () => $svc->review($b, $cardA['id'], ['result' => 'recalled']),
        NotFoundException::class
    );
    checkThrows(
        'isolation: b cannot change a\'s card status',
        fn () => $svc->changeStatus($b, $cardA['id'], FlipCardStatus::Archived),
        NotFoundException::class
    );
    checkThrows(
        'isolation: b cannot delete a\'s card',
        fn () => $svc->delete($b, $cardA['id']),
        NotFoundException::class
    );
    checkEquals('isolation: a\'s cards intact', 3, $cardCount($a));

    // === explicit delete =====================================================
    $deleted = $svc->delete($a, $cardA['id']);
    checkEquals('delete: acknowledges', ['card_id' => $cardA['id'], 'deleted' => true], $deleted);
    checkEquals('delete: card row gone', 2, $cardCount($a));
    checkEquals('delete: review history cascades', 0, $reviewCount($cardA['id']));
    checkThrows(
        'delete: gone afterwards',
        fn () => $svc->detail($a, $cardA['id']),
        NotFoundException::class
    );
    checkThrows(
        'delete: deleting again is 404',
        fn () => $svc->delete($a, $cardA['id']),
        NotFoundException::class
    );
    checkEquals('delete: siblings untouched', 2, $cardCount($a));
    checkEquals('delete: sibling history intact', 1, $reviewCount($cardB['id']));
} finally {
    Database::run('DELETE FROM users WHERE email IN (?, ?)', [$aEmail, $bEmail]);
}

exit(summary('FlipCard'));
