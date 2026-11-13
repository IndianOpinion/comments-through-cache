<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

/**
 * Turns comment events into queued purges (C22 to C28).
 *
 * Core reports statuses as "approved", "unapproved", "spam", "trash" and, for
 * a permanent delete, "delete". The raw values "1" and "0" are mapped too.
 */
final class ModerationListener
{
    public function __construct(private PurgeQueue $queue)
    {
    }

    /**
     * Handler for wp_insert_comment (C22, C23).
     */
    public function onInsert(mixed $commentId = 0, mixed $comment = null): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }
        $status = self::normalise((string) $comment->comment_approved);
        if ($status !== 'approved') {
            return;
        }
        $this->maybeQueue($comment, 'approved', 'new', 'comment_approved_on_post');
    }

    /**
     * Handler for transition_comment_status (C24 to C26, C28).
     */
    public function onTransition(mixed $new = '', mixed $old = '', mixed $comment = null): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }
        $new = self::normalise((string) $new);
        $old = self::normalise((string) $old);
        if ($new === $old) {
            return; // C26.
        }
        if ($new === 'approved') {
            $this->maybeQueue($comment, $new, $old, 'status_to_approved');
        } elseif ($old === 'approved') {
            $this->maybeQueue($comment, $new, $old, 'status_from_approved');
        }
    }

    /**
     * Handler for edit_comment (C27).
     */
    public function onEdit(mixed $commentId = 0): void
    {
        $comment = get_comment((int) $commentId);
        if (!$comment instanceof \WP_Comment) {
            return;
        }
        if (self::normalise((string) $comment->comment_approved) !== 'approved') {
            return;
        }
        $this->maybeQueue($comment, 'approved', 'approved', 'approved_comment_edited');
    }

    public static function normalise(string $status): string
    {
        return match ($status) {
            '1', 'approve', 'approved' => 'approved',
            '0', 'hold', 'unapproved' => 'unapproved',
            default => $status,
        };
    }

    private function maybeQueue(\WP_Comment $comment, string $new, string $old, string $reason): void
    {
        if (!(bool) apply_filters('comments_through_cache_should_purge', true, $comment, $new, $old)) {
            return;
        }
        $this->queue->add((int) $comment->comment_post_ID, $reason);
    }
}
