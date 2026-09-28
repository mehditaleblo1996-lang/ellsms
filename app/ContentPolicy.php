<?php
/**
 * #40 — prohibited words in message content.
 *
 * Platform admins keep a list (ellsms_prohibited_words, /admin/prohibited-words). A message containing
 * an active pattern is refused on every send path — direct, scheduled, auto-reply, bulk/import/gradual,
 * public API, legacy URL API — before any credit is reserved; a bulk row with such text fails with a
 * clear reason and is not charged. Same idea as Vesal's TBL_PROHIBITEDWORDS check
 * (MessageUtil.contentMessageValidation), with normalization so trivial variants do not slip through.
 */

declare(strict_types=1);

const CONTENT_POLICY_ERROR = 'متن پیام شامل عبارت غیرمجاز است و ارسال نشد — هزینه‌ای کسر نشد.';

/**
 * Both the patterns and the text go through this before comparing: Arabic ي/ى/ك → Persian ی/ک,
 * ZWNJ/ZWJ/bidi marks, tatweel and Arabic diacritics removed, digits → Latin, whitespace collapsed,
 * lower-cased.
 */
function content_policy_normalize(string $text): string {
    $text = strtr($text, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
    $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
    $text = from_persian_digits($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_strtolower(trim($text), 'UTF-8');
}

/** Active patterns, normalized and compiled; cached ~30 s per process (resettable). */
function content_policy_rules(): array {
    $state = &$GLOBALS['__content_policy_rules'];
    if (is_array($state) && microtime(true) - $state['at'] <= 30) {
        return $state['rules'];
    }
    $rules = [];
    try {
        foreach (db()->query('SELECT id, pattern, match_type FROM ellsms_prohibited_words WHERE active = 1 ORDER BY id') as $row) {
            $pattern = content_policy_normalize((string)$row['pattern']);
            if ($pattern === '') continue;
            $rules[] = [
                'id' => (int)$row['id'],
                'type' => (string)$row['match_type'],
                'needle' => $pattern,
                // Whole word = not glued to another letter or digit on either side (\b does not
                // understand Persian letters).
                'regex' => '/(?<![\p{L}\p{N}])' . preg_quote($pattern, '/') . '(?![\p{L}\p{N}])/u',
            ];
        }
    } catch (PDOException $e) {
        // 2026_09_29_prohibited_words.sql not applied yet: no rules, nothing refused.
        if (!str_contains($e->getMessage(), "doesn't exist")) throw $e;
    }
    $state = ['at' => microtime(true), 'rules' => $rules];
    return $rules;
}

function content_policy_reset(): void {
    $GLOBALS['__content_policy_rules'] = null;
}

/** The id of the first rule $content violates, or null when it is allowed. */
function content_policy_violation(string $content): ?int {
    $rules = content_policy_rules();
    if ($rules === []) return null;
    $text = content_policy_normalize($content);
    foreach ($rules as $rule) {
        $hit = $rule['type'] === 'word' ? preg_match($rule['regex'], $text) === 1 : str_contains($text, $rule['needle']);
        if ($hit) return $rule['id'];
    }
    return null;
}

/** Logged whenever a send is refused — the rule id, never the text. */
function content_policy_log_refusal(int $ruleId, string $path, ?int $userId): void {
    Logger::warning('content_policy.refused', ['rule_id' => $ruleId, 'path' => $path, 'user_id' => $userId]);
    Metrics::increment('content_policy.refused', 1, ['path' => $path]);
}
