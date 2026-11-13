<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Prints a plain "your comment is held" notice above the comment form
 * (C19a to C19g).
 */
final class HeldNotice
{
    public const CSS_CLASS = 'comments-through-cache-held-notice';

    private static ?self $current = null;

    private bool $templateCalled = false;

    private bool $printed = false;

    public function __construct(private Config $config)
    {
    }

    /**
     * Makes this instance the one the template function and render() use.
     */
    public function activate(): void
    {
        self::$current = $this;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Handler for comment_form_before (C19a, C19b).
     */
    public function onCommentFormBefore(): void
    {
        if ($this->templateCalled || $this->printed) {
            return;
        }
        $html = $this->html();
        if ($html !== '') {
            $this->printed = true;
            echo $html; // Built from escaped parts in html().
        }
    }

    /**
     * Called by the template function: returns the notice and stops the form
     * action from printing it again in this request (C19f).
     */
    public function forTemplate(): string
    {
        $this->templateCalled = true;
        if ($this->printed) {
            return '';
        }
        $html = $this->html();
        if ($html !== '') {
            $this->printed = true;
        }
        return $html;
    }

    /**
     * The notice markup for the current request, or an empty string. Used by
     * the template function.
     */
    public static function render(): string
    {
        return self::$current === null ? '' : self::$current->forTemplate();
    }

    /**
     * The notice markup for the current post, or an empty string.
     */
    public function html(): string
    {
        if (!$this->config->noticeEnabled()) {
            return '';
        }
        $postId = (int) get_the_ID();
        if ($postId <= 0) {
            return '';
        }
        $context = $this->context($postId);
        if ($context === null) {
            return '';
        }
        if (!(bool) apply_filters('comments_through_cache_show_held_notice', true, $postId, $context)) {
            return '';
        }
        $default = __('Thank you. Your comment has been received and will appear once it has been approved.', 'comments-through-cache');
        $text = apply_filters('comments_through_cache_held_notice_text', $default, $postId);
        if (!is_string($text) || trim($text) === '') {
            return '';
        }
        return '<p class="' . esc_attr(self::CSS_CLASS) . '" role="status">' . esc_html($text) . '</p>';
    }

    /**
     * Which trigger applies: "redirect" (C19a), "cookie" (C19b) or none.
     */
    private function context(int $postId): ?string
    {
        if ($this->redirectApplies($postId)) {
            return 'redirect';
        }
        if ($this->cookieApplies($postId)) {
            return 'cookie';
        }
        return null;
    }

    /**
     * C19a: the redirect URL names a held comment on this post, and core
     * accepts the hash (core owns the validity window) or the reader's cookie
     * names the same author.
     */
    private function redirectApplies(int $postId): bool
    {
        $id = $_GET['unapproved'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || !isset($_GET['moderation-hash'])) {
            return false;
        }
        $comment = get_comment((int) $id);
        if (!$comment instanceof \WP_Comment) {
            return false;
        }
        if ((int) $comment->comment_post_ID !== $postId || (string) $comment->comment_approved !== '0') {
            return false; // C19c.
        }
        $email = (string) wp_get_unapproved_comment_author_email();
        $author = (string) $comment->comment_author_email;
        return $email !== '' && $author !== '' && strtolower($email) === strtolower($author);
    }

    /**
     * C19b: the reader's email cookie has at least one held comment on this
     * post. One indexed query, only on a request that bypasses the cache.
     */
    private function cookieApplies(int $postId): bool
    {
        $commenter = wp_get_current_commenter();
        $email = is_array($commenter) && isset($commenter['comment_author_email']) ? (string) $commenter['comment_author_email'] : '';
        if ($email === '') {
            return false;
        }
        $count = get_comments([
            'post_id' => $postId,
            'author_email' => $email,
            'status' => 'hold',
            'count' => true,
        ]);
        return is_numeric($count) && (int) $count > 0;
    }
}
