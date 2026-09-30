<?php
function sanitize($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function format_currency($amount) {
    return '$' . number_format((float)$amount, 2);
}

function get_status_badge($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'active':
        case 'open':
        case 'accepted':
        case 'approved':
            return '<span class="status-badge success">' . ucwords(str_replace('_', ' ', $status)) . '</span>';
        case 'pending':
        case 'in_progress':
        case 'submitted':
            return '<span class="status-badge warning">' . ucwords(str_replace('_', ' ', $status)) . '</span>';
        case 'declined':
        case 'cancelled':
        case 'closed':
        case 'suspended':
        case 'changes_requested':
        case 'action_taken':
            return '<span class="status-badge danger">' . ucwords(str_replace('_', ' ', $status)) . '</span>';
        case 'completed':
        case 'reviewed':
            return '<span class="status-badge primary">' . ucwords(str_replace('_', ' ', $status)) . '</span>';
        case 'dismissed':
            return '<span class="status-badge muted" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3);">Dismissed</span>';
        default:
            return '<span class="status-badge">' . ucwords(str_replace('_', ' ', $status)) . '</span>';
    }
}

function get_report_reason_label($reason) {
    $reasons = [
        'scam_phishing' => 'Scam / Phishing / Fraud',
        'off_platform'  => 'Off-Platform Escrow Bypass',
        'fake_profile'  => 'Fake Identity / Impersonation',
        'harassment'    => 'Harassment / Abusive Conduct',
        'spam'          => 'Spam / Bot Promotion',
        'other'         => 'Other Suspicious Activity'
    ];
    return $reasons[$reason] ?? ucwords(str_replace('_', ' ', $reason));
}

function get_report_reason_badge($reason) {
    $label = get_report_reason_label($reason);
    $color_map = [
        'scam_phishing' => 'background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);',
        'off_platform'  => 'background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);',
        'fake_profile'  => 'background: rgba(168, 85, 247, 0.15); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3);',
        'harassment'    => 'background: rgba(244, 63, 94, 0.15); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.3);',
        'spam'          => 'background: rgba(14, 165, 233, 0.15); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.3);',
        'other'         => 'background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3);',
    ];
    $style = $color_map[$reason] ?? 'background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3);';
    return '<span style="display: inline-block; font-size: 0.75rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; ' . $style . '">' . htmlspecialchars($label) . '</span>';
}

function time_ago($datetime) {
    if (!$datetime) return 'Recently';
    $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
    if (!$time || $time <= 0) return 'Recently';
    $diff = time() - $time;
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

/**
 * Formats project duration ENUM keys or proposal duration strings into clean human-readable labels.
 */
function format_duration($duration) {
    if (empty($duration)) {
        return 'Not specified';
    }
    
    $map = [
        'less_1w'  => 'Less than 1 week',
        '1_4w'     => '1–4 weeks',
        '1_3m'     => '1–3 months',
        '3m_plus'  => '3+ months',
        '< 1 week' => 'Less than 1 week',
    ];
    
    $key = strtolower(trim($duration));
    if (isset($map[$key])) {
        return $map[$key];
    }
    
    return htmlspecialchars(ucwords(str_replace('_', ' ', $duration)));
}

function build_filter_url($base_url, $exclude_key, $exclude_value = null, $also_exclude = []) {
    $params = $_GET;
    foreach ($also_exclude as $ak) {
        unset($params[$ak]);
    }
    if ($exclude_value === null) {
        unset($params[$exclude_key]);
    } else {
        if (isset($params[$exclude_key])) {
            if (is_array($params[$exclude_key])) {
                $params[$exclude_key] = array_values(array_diff($params[$exclude_key], [$exclude_value]));
                if (empty($params[$exclude_key])) {
                    unset($params[$exclude_key]);
                }
            } else {
                unset($params[$exclude_key]);
            }
        }
    }
    return $base_url . (!empty($params) ? '?' . http_build_query($params) : '');
}

/**
 * Computes pagination metadata, clamping the current page between 1 and total_pages.
 */
function paginate($total_items, $per_page = 6, $param_name = 'page') {
    $total_items = max(0, intval($total_items));
    $per_page = max(1, intval($per_page));
    $total_pages = max(1, (int)ceil($total_items / $per_page));
    $current_page = max(1, intval($_GET[$param_name] ?? 1));
    if ($current_page > $total_pages) {
        $current_page = $total_pages;
    }
    $offset = ($current_page - 1) * $per_page;
    return [
        'page' => $current_page,
        'current_page' => $current_page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'total_items' => $total_items,
        'offset' => $offset,
        'param_name' => $param_name
    ];
}

/**
 * Renders an accessible, responsive pagination control preserving active GET parameters.
 */
function render_pagination($pagination, $base_url = '') {
    $p = $pagination;
    if (($p['total_items'] ?? 0) <= 0) return '';
    
    $current_page = (int)($p['page'] ?? $p['current_page'] ?? 1);
    $per_page = (int)($p['per_page'] ?? 6);
    $total_pages = (int)($p['total_pages'] ?? 1);
    $total_items = (int)($p['total_items'] ?? 0);
    $param_name = $p['param_name'] ?? 'page';
    
    $start = ($current_page - 1) * $per_page + 1;
    $end = min($total_items, $current_page * $per_page);
    
    $build_url = function($page_num) use ($base_url, $param_name) {
        $params = $_GET;
        $params[$param_name] = $page_num;
        return ($base_url ?: '') . '?' . http_build_query($params);
    };
    
    ob_start();
    ?>
    <div class="pagination-wrapper">
        <div class="pagination-summary">
            Showing <strong><?= $start ?>–<?= $end ?></strong> of <strong><?= $total_items ?></strong> items
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="pagination-nav" aria-label="Pagination Navigation">
                <?php if ($current_page > 1): ?>
                    <a href="<?= htmlspecialchars($build_url($current_page - 1)) ?>" class="page-link" aria-label="Previous page">&laquo; Prev</a>
                <?php else: ?>
                    <span class="page-link disabled">&laquo; Prev</span>
                <?php endif; ?>

                <?php
                $pages = [];
                if ($total_pages <= 7) {
                    $pages = range(1, $total_pages);
                } else {
                    if ($current_page <= 4) {
                        $pages = [1, 2, 3, 4, 5, '...', $total_pages];
                    } elseif ($current_page >= $total_pages - 3) {
                        $pages = [1, '...', $total_pages - 4, $total_pages - 3, $total_pages - 2, $total_pages - 1, $total_pages];
                    } else {
                        $pages = [1, '...', $current_page - 1, $current_page, $current_page + 1, '...', $total_pages];
                    }
                }
                foreach ($pages as $pg): ?>
                    <?php if ($pg === '...'): ?>
                        <span class="page-ellipsis">&hellip;</span>
                    <?php elseif ($pg == $current_page): ?>
                        <span class="page-link active" aria-current="page"><?= $pg ?></span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($build_url($pg)) ?>" class="page-link"><?= $pg ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?= htmlspecialchars($build_url($current_page + 1)) ?>" class="page-link" aria-label="Next page">Next &raquo;</a>
                <?php else: ?>
                    <span class="page-link disabled">Next &raquo;</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renders text with an interactive "See more / See less" toggle if it exceeds the threshold.
 *
 * @param string $text The full text content.
 * @param int $threshold The character threshold to trigger clamping (default: 180).
 * @param int $clamp_lines Number of lines to visually clamp to (2, 3, or 4; default: 3).
 * @param string $extra_classes Optional CSS classes to attach to the container/paragraph.
 * @return string Rendered HTML.
 */
function render_expandable_text($text, $threshold = 180, $clamp_lines = 3, $extra_classes = '') {
    $trimmed = trim((string)$text);
    if ($trimmed === '') return '';

    $safe_html = nl2br(htmlspecialchars($trimmed));
    $cls_attr = !empty($extra_classes) ? ' class="' . htmlspecialchars($extra_classes) . '"' : '';

    if (mb_strlen($trimmed) <= $threshold) {
        return '<p' . $cls_attr . '>' . $safe_html . '</p>';
    }

    $clamp_class = ($clamp_lines === 4) ? 'clamped-4' : (($clamp_lines === 2) ? 'clamped-2' : 'clamped-3');
    $content_classes = 'expandable-content ' . $clamp_class . (!empty($extra_classes) ? ' ' . htmlspecialchars($extra_classes) : '');

    return '<div class="expandable-text"><div class="' . $content_classes . '">' . $safe_html . '</div><button type="button" class="see-more-btn" aria-expanded="false">See more <i data-lucide="chevron-down" class="icon-inline"></i></button></div>';
}

/**
 * Formats statistics numbers cleanly (e.g. 7, 25+, 1,400+, 1.5M+).
 */
function format_stat_number($count) {
    $count = (int)$count;
    if ($count >= 1000000) {
        return round($count / 1000000, 1) . 'M+';
    } elseif ($count >= 1000) {
        return number_format($count) . '+';
    } elseif ($count >= 10) {
        return number_format($count) . '+';
    }
    return number_format($count);
}

/**
 * Formats monetary amounts for stats/hero sections (e.g. $500, $6,100+, $2.8M+).
 */
function format_stat_currency($amount) {
    $amount = (float)$amount;
    if ($amount >= 1000000) {
        return '$' . round($amount / 1000000, 1) . 'M+';
    } elseif ($amount >= 10000) {
        return '$' . round($amount / 1000, 1) . 'k+';
    } elseif ($amount >= 1000) {
        return '$' . number_format($amount, 0) . '+';
    } elseif ($amount > 0) {
        return '$' . number_format($amount, 0);
    }
    return '$0';
}


