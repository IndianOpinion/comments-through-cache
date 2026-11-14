<?php

declare(strict_types=1);

namespace IndianOpinion\CommentsThroughCache;

use IndianOpinion\CommentsThroughCache\Purger\ActionPurger;
use IndianOpinion\CommentsThroughCache\Purger\Configurable;
use IndianOpinion\CommentsThroughCache\Purger\Purger;

/**
 * One Site Health test: configured purgers, configuration errors (critical),
 * and a recommendation when only the action purger runs and nothing listens.
 */
final class SiteHealth
{
    /** @var callable(): list<Purger> */
    private $purgers;

    /**
     * @param callable(): list<Purger> $purgers
     */
    public function __construct(private Config $config, callable $purgers)
    {
        $this->purgers = $purgers;
    }

    /**
     * Handler for the site_status_tests filter.
     *
     * @param mixed $tests
     *
     * @return mixed
     */
    public function register(mixed $tests): mixed
    {
        if (!is_array($tests)) {
            return $tests;
        }
        $tests['direct']['comments_through_cache'] = [
            'label' => __('Comments through cache', 'comments-through-cache'),
            'test' => [$this, 'run'],
        ];
        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    public function run(): array
    {
        $problems = $this->config->problems();
        $names = [];
        $purgers = ($this->purgers)();
        foreach ($purgers as $purger) {
            $names[] = $purger->name();
            if ($purger instanceof Configurable) {
                foreach ($purger->configurationProblems() as $problem) {
                    $problems[] = $problem;
                }
            }
        }
        $list = $names === [] ? __('none', 'comments-through-cache') : implode(', ', $names);

        if ($problems !== []) {
            $status = 'critical';
            $label = __('Comment cache purging is misconfigured', 'comments-through-cache');
            $body = '<p>' . esc_html(sprintf(__('Active purgers: %s.', 'comments-through-cache'), $list)) . '</p><ul>';
            foreach ($problems as $problem) {
                $body .= '<li>' . esc_html($problem) . '</li>';
            }
            $body .= '</ul>';
        } elseif ($names === ['action'] && !ActionPurger::hasListener()) {
            $status = 'recommended';
            $label = __('Approved comments wait for the page cache to expire', 'comments-through-cache');
            $body = '<p>' . esc_html__(
                'Only the action purger is active and nothing listens to it, so approved comments appear when the cached page expires. '
                . 'Configure the file, http or cloudflare purger, or hook your cache plugin to comments_through_cache_purge.',
                'comments-through-cache'
            ) . '</p>';
        } else {
            $status = 'good';
            $label = __('Approved comments purge the article from the page cache', 'comments-through-cache');
            $body = '<p>' . esc_html(sprintf(__('Active purgers: %s.', 'comments-through-cache'), $list)) . '</p>';
        }

        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Performance', 'comments-through-cache'), 'color' => 'blue'],
            'description' => $body,
            'actions' => '',
            'test' => 'comments_through_cache',
        ];
    }
}
