<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Sets the commenter cookie(s) for an anonymous reader who did not tick the
 * consent box, so their held comment survives a refresh and their requests
 * bypass the page cache (cases C1 to C12). Clears the cookie early once the
 * reader has no held comments left (open question 3).
 */
final class CookieSetter
{
    /** Core runs its own handler at 10; ours must send its headers after. */
    public const PRIORITY = 20;

    public const MARKER_PREFIX = 'comments_through_cache_';

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): int|null $clock Unix time source.
     */
    public function __construct(
        private Config $config,
        private Logger $logger,
        private ResponseHeaders $response,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Handler for core's set_comment_cookies action.
     */
    public function onSetCommentCookies(mixed $comment = null, mixed $user = null, mixed $consent = false): void
    {
        if (!$comment instanceof \WP_Comment) {
            return; // C8.
        }
        if ($user instanceof \WP_User && $user->exists()) {
            return; // C4.
        }
        if ($consent === true || $consent === 1 || $consent === '1') {
            // C3: core has set its own long-lived cookies by the reader's
            // choice. Drop our marker so early expiry never touches them.
            $this->expireMarkerIfPresent();
            return;
        }
        $status = (string) $comment->comment_approved;
        if ($status !== '0' && $status !== '1') {
            return; // C5: spam, trash or anything unknown gets no bypass.
        }

        $allowed = (bool) apply_filters('comments_through_cache_should_set_cookie', true, $comment, $user, false);
        if (!$allowed) {
            return; // C11.
        }

        if ($this->response->headersSent()) {
            // C9: the comment is saved; only the preview is lost.
            $this->logger->debugOnce('headers-sent', 'Headers already sent when the comment cookie action fired; no cookie set.');
            return;
        }

        $lifetime = $this->lifetime($comment);
        $fields = $this->fields($comment);
        $values = $this->cookieValues($comment, $fields);
        if ($values === []) {
            return;
        }

        $now = ($this->clock)();
        $base = $this->baseOptions($now + $lifetime);
        foreach ($values as $name => $value) {
            $this->response->setCookie($name, $value, $base);
        }
        $this->response->setCookie(
            self::markerName(),
            $now . '.' . ($fields === Config::FIELDS_ALL ? 'a' : 'e'),
            ['httponly' => true, 'samesite' => 'Lax'] + $base
        );

        $set = [];
        foreach (array_keys($values) as $name) {
            $set[] = self::fieldOf($name);
        }
        do_action('comments_through_cache_cookie_set', (int) $comment->comment_ID, $set, $lifetime);
    }

    /**
     * Handler for send_headers: expire our cookie early once the reader has no
     * held comments left on the site.
     */
    public function maybeExpireEarly(): void
    {
        if (!$this->config->earlyExpiry()) {
            return;
        }
        $marker = $_COOKIE[self::markerName()] ?? null;
        if (!is_string($marker) || preg_match('/^(\d{1,12})\.([ae])$/', $marker, $m) !== 1) {
            return;
        }
        if ($this->response->headersSent()) {
            return;
        }
        $setAt = (int) $m[1];
        $all = $m[2] === 'a';

        $commenter = wp_get_current_commenter();
        $email = is_array($commenter) && isset($commenter['comment_author_email']) ? (string) $commenter['comment_author_email'] : '';

        if ($email !== '') {
            $grace = (int) apply_filters('comments_through_cache_early_expiry_grace', Config::EARLY_EXPIRY_GRACE_DEFAULT);
            if (($this->clock)() - $setAt < max(0, $grace)) {
                return;
            }
            $held = get_comments([
                'author_email' => $email,
                'status' => 'hold',
                'count' => true,
            ]);
            if (is_numeric($held) && (int) $held > 0) {
                return;
            }
        }

        // This response sets cookies on a request that may no longer carry a
        // bypass cookie. Keep it out of every cache even if a layer breaks
        // C15, or the expiry would be replayed to other readers.
        $this->response->header('Cache-Control', 'private, no-store, max-age=0');
        $this->response->header('X-Accel-Expires', '0');

        $past = $this->baseOptions(($this->clock)() - 31536000);
        $cleared = ['email'];
        $this->response->setCookie('comment_author_email_' . self::cookieHash(), ' ', $past);
        if ($all) {
            $this->response->setCookie('comment_author_' . self::cookieHash(), ' ', $past);
            $this->response->setCookie('comment_author_url_' . self::cookieHash(), ' ', $past);
            $cleared = ['name', 'email', 'url'];
        }
        $this->response->setCookie(self::markerName(), ' ', ['httponly' => true, 'samesite' => 'Lax'] + $past);
        do_action('comments_through_cache_cookie_cleared', $cleared);
    }

    public static function markerName(): string
    {
        return self::MARKER_PREFIX . self::cookieHash();
    }

    private function expireMarkerIfPresent(): void
    {
        if (!isset($_COOKIE[self::markerName()]) || $this->response->headersSent()) {
            return;
        }
        $this->response->setCookie(
            self::markerName(),
            ' ',
            ['httponly' => true, 'samesite' => 'Lax'] + $this->baseOptions(($this->clock)() - 31536000)
        );
    }

    private function lifetime(\WP_Comment $comment): int
    {
        try {
            $base = $this->config->cookieLifetime();
        } catch (ConfigurationException $e) {
            $this->logger->errorOnce('lifetime', $e->getMessage() . ' Using the default.');
            $base = Config::LIFETIME_DEFAULT;
        }
        $filtered = apply_filters('comments_through_cache_cookie_lifetime', $base, $comment);
        $seconds = is_numeric($filtered) ? (int) $filtered : Config::LIFETIME_DEFAULT;
        [$clamped, $changed] = Config::clampLifetime($seconds);
        if ($changed) {
            $this->logger->debug(sprintf('Cookie lifetime %d is out of range; clamped to %d.', $seconds, $clamped));
        }
        return $clamped;
    }

    private function fields(\WP_Comment $comment): string
    {
        try {
            $base = $this->config->cookieFields();
        } catch (ConfigurationException $e) {
            $this->logger->errorOnce('fields', $e->getMessage() . ' Using "email".');
            $base = Config::FIELDS_EMAIL;
        }
        $filtered = apply_filters('comments_through_cache_cookie_fields', $base, $comment);
        try {
            return Config::validateFields($filtered);
        } catch (ConfigurationException $e) {
            $this->logger->errorOnce('fields-filter', 'The cookie fields filter returned an invalid value. Using "email".');
            return Config::FIELDS_EMAIL;
        }
    }

    /**
     * Cookie names and values in core's order: name, email, URL.
     *
     * @return array<string, string>
     */
    private function cookieValues(\WP_Comment $comment, string $fields): array
    {
        $hash = self::cookieHash();
        $values = [];
        if ($fields === Config::FIELDS_ALL && (string) $comment->comment_author !== '') {
            $values['comment_author_' . $hash] = (string) $comment->comment_author;
        }
        if ((string) $comment->comment_author_email !== '') {
            $values['comment_author_email_' . $hash] = (string) $comment->comment_author_email;
        }
        if ($fields === Config::FIELDS_ALL && (string) $comment->comment_author_url !== '') {
            $values['comment_author_url_' . $hash] = esc_url((string) $comment->comment_author_url);
        }
        return $values;
    }

    /**
     * Core's cookie attributes: path, domain, and secure when home is HTTPS.
     *
     * @return array{expires: int, path: string, domain: string, secure: bool, httponly: bool}
     */
    private function baseOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => defined('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '',
            'secure' => 'https' === parse_url((string) home_url(), PHP_URL_SCHEME),
            'httponly' => false,
        ];
    }

    private static function cookieHash(): string
    {
        return defined('COOKIEHASH') ? (string) constant('COOKIEHASH') : md5((string) home_url());
    }

    private static function fieldOf(string $cookieName): string
    {
        if (str_starts_with($cookieName, 'comment_author_email_')) {
            return 'email';
        }
        if (str_starts_with($cookieName, 'comment_author_url_')) {
            return 'url';
        }
        return 'name';
    }
}
